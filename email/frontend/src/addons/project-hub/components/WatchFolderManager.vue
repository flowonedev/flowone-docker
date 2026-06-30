<template>
  <div class="flex-1 flex flex-col overflow-hidden">
    <div class="flex items-center gap-3 px-5 py-3 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-900 shrink-0">
      <div class="flex bg-surface-100 dark:bg-surface-700 rounded-full p-0.5">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          class="px-4 py-1.5 rounded-full text-[11px] font-medium transition-colors flex items-center gap-1.5"
          :class="activeTab === tab.id
            ? 'bg-white dark:bg-surface-600 text-surface-800 dark:text-surface-100 shadow-sm'
            : 'text-surface-500 dark:text-surface-400'"
        >
          <span class="material-symbols-rounded text-[14px]">{{ tab.icon }}</span>
          {{ tab.label }}
        </button>
      </div>
    </div>

    <div class="flex-1 overflow-auto px-5 py-4">
      <!-- Watch Folders Tab -->
      <div v-if="activeTab === 'folders'">
        <div class="flex items-center justify-between mb-4">
          <p class="text-sm text-surface-500 dark:text-surface-400">Set the folder path once -- works for everyone in the office.</p>
          <button
            @click="openCreateDialog()"
            class="flex items-center gap-1.5 px-4 py-1.5 rounded-full text-sm font-medium bg-amber-500 text-white hover:bg-amber-600 transition-colors"
          >
            <span class="material-symbols-rounded text-[16px]">create_new_folder</span>
            New Watch Folder
          </button>
        </div>

        <div v-if="loading" class="flex items-center justify-center py-16">
          <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
        </div>

        <div v-else-if="displayFolders.length === 0" class="text-center py-16 text-surface-400">
          <span class="material-symbols-rounded text-5xl mb-3 block">folder_off</span>
          <p class="text-base font-medium text-surface-600 dark:text-surface-300 mb-1">No watch folders yet</p>
          <p class="text-sm">Create a watch folder to track file edits for the team</p>
        </div>

        <div v-else class="space-y-2">
          <div
            v-for="folder in displayFolders"
            :key="folder.id"
            class="flex items-center gap-3 p-4 rounded-xl bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700"
          >
            <span class="material-symbols-rounded text-lg text-amber-500">folder_eye</span>
            <div class="flex-1 min-w-0">
              <div class="text-sm font-medium text-surface-700 dark:text-surface-200">{{ folder.name }}</div>
              <div class="text-xs text-surface-400 truncate font-mono">{{ folder.folder_path }}</div>
              <div class="flex items-center gap-2 mt-1">
                <span class="text-xs px-2 py-0.5 rounded-full bg-blue-500/10 text-blue-600 dark:text-blue-400">{{ folder.client_name }}</span>
                <span v-if="folder.board_name" class="text-xs px-2 py-0.5 rounded-full bg-green-500/10 text-green-600 dark:text-green-400">{{ folder.board_name }}</span>
              </div>
            </div>
            <button @click="deleteFolder(folder.id)" class="p-1.5 rounded-lg text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors">
              <span class="material-symbols-rounded text-lg">delete</span>
            </button>
          </div>
        </div>

        <!-- Create Folder Modal -->
        <Teleport to="body">
          <div v-if="showCreateFolder" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50" @click.self="showCreateFolder = false">
            <div class="bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 rounded-xl w-[420px] p-6 shadow-xl">
              <h3 class="text-base font-semibold text-surface-800 dark:text-surface-100 mb-4 flex items-center gap-2">
                <span class="material-symbols-rounded text-amber-500">create_new_folder</span>
                New Watch Folder
              </h3>
              <div class="space-y-3">
                <div>
                  <label class="text-xs text-surface-500 dark:text-surface-400 block mb-1">Name</label>
                  <input v-model="folderForm.name" class="w-full px-3 py-2 rounded-lg bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 text-surface-800 dark:text-surface-200 text-sm outline-none focus:ring-2 focus:ring-primary-500/30" placeholder="Design files" />
                </div>
                <div>
                  <label class="text-xs text-surface-500 dark:text-surface-400 block mb-1">Client</label>
                  <select v-model="folderForm.client_id" class="w-full px-3 py-2 rounded-lg bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 text-surface-800 dark:text-surface-200 text-sm outline-none cursor-pointer">
                    <option :value="null" disabled>Select client...</option>
                    <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.name }}</option>
                  </select>
                </div>
                <div v-if="!boardId">
                  <label class="text-xs text-surface-500 dark:text-surface-400 block mb-1">Board</label>
                  <select v-model="folderForm.board_id" class="w-full px-3 py-2 rounded-lg bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 text-surface-800 dark:text-surface-200 text-sm outline-none cursor-pointer">
                    <option :value="null">Select board...</option>
                    <option v-for="b in folderBoards" :key="b.board_id || b.id" :value="b.board_id || b.id">{{ b.board_name || b.name }}</option>
                  </select>
                </div>
                <div>
                  <label class="text-xs text-surface-500 dark:text-surface-400 block mb-1">Folder Path</label>
                  <input
                    v-model="folderForm.folder_path"
                    class="w-full px-3 py-2 rounded-lg bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 text-surface-800 dark:text-surface-200 text-sm outline-none focus:ring-2 focus:ring-primary-500/30 font-mono"
                    placeholder="Z:\Clients\BV Boros\Design"
                  />
                  <p class="text-[11px] text-surface-400 dark:text-surface-500 mt-1.5 flex items-start gap-1">
                    <span class="material-symbols-rounded text-[13px] mt-px shrink-0">info</span>
                    <span>Full path as it appears on office machines. Remote workers can set a path override.</span>
                  </p>
                </div>
              </div>
              <div class="flex justify-end gap-2 mt-5">
                <button @click="showCreateFolder = false" class="px-4 py-2 rounded-full text-sm bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors">Cancel</button>
                <button @click="createFolder" :disabled="!folderForm.name || !folderForm.folder_path || !folderForm.client_id" class="px-4 py-2 rounded-full text-sm font-medium bg-amber-500 text-white hover:bg-amber-600 disabled:opacity-50 transition-colors">Create</button>
              </div>
            </div>
          </div>
        </Teleport>
      </div>

      <!-- Path Overrides Tab (admin) -->
      <div v-if="activeTab === 'overrides'">
        <p class="text-sm text-surface-500 dark:text-surface-400 mb-4">
          Path overrides let remote workers remap folder paths. Example: replace <code class="bg-surface-100 dark:bg-surface-700 px-1 rounded text-xs">Z:\</code> with <code class="bg-surface-100 dark:bg-surface-700 px-1 rounded text-xs">\\192.168.1.106\share</code>
        </p>

        <div v-if="loading" class="flex items-center justify-center py-16">
          <div class="animate-spin rounded-full h-8 w-8 border-2 border-primary-500 border-t-transparent"></div>
        </div>

        <div v-else class="space-y-3">
          <div
            v-for="member in teamStatus"
            :key="member.email"
            class="rounded-xl bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 overflow-hidden"
          >
            <div class="flex items-center justify-between p-4 cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-700/50 transition-colors" @click="toggleMember(member.email)">
              <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-base text-surface-400">person</span>
                <div>
                  <div class="text-sm font-medium text-surface-700 dark:text-surface-200">{{ member.display_name || member.email }}</div>
                  <div class="text-xs text-surface-400">{{ member.email }}</div>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <span class="text-xs px-2 py-0.5 rounded-full" :class="member.override_count > 0 ? 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400' : 'bg-surface-200/50 dark:bg-surface-700 text-surface-400'">
                  {{ member.override_count || 0 }} override{{ member.override_count !== 1 ? 's' : '' }}
                </span>
                <span class="material-symbols-rounded text-surface-400 text-lg transition-transform" :class="expandedMembers.has(member.email) ? 'rotate-180' : ''">expand_more</span>
              </div>
            </div>

            <div v-if="expandedMembers.has(member.email)" class="border-t border-surface-200 dark:border-surface-700 p-4">
              <div v-if="memberOverrides[member.email]" class="space-y-2">
                <div
                  v-for="ov in memberOverrides[member.email]"
                  :key="ov.id"
                  class="flex items-center gap-3 p-3 rounded-lg bg-white dark:bg-surface-900"
                >
                  <span class="material-symbols-rounded text-base text-indigo-500">swap_horiz</span>
                  <div class="flex-1 min-w-0">
                    <div class="text-xs text-surface-400 flex items-center gap-1">
                      <code class="bg-surface-100 dark:bg-surface-700 px-1 rounded text-[11px] font-mono">{{ ov.match_prefix }}</code>
                      <span class="material-symbols-rounded text-[12px]">arrow_forward</span>
                      <code class="bg-surface-100 dark:bg-surface-700 px-1 rounded text-[11px] font-mono">{{ ov.replace_prefix }}</code>
                    </div>
                    <div v-if="ov.label" class="text-xs text-surface-500 mt-0.5">{{ ov.label }}</div>
                  </div>
                  <button @click="deleteTeamOverride(member.email, ov.id)" class="p-1 rounded text-surface-400 hover:text-red-500 transition-colors">
                    <span class="material-symbols-rounded text-base">delete</span>
                  </button>
                </div>
              </div>
              <div v-else class="text-sm text-surface-400">Loading...</div>

              <div class="mt-3 flex items-center gap-2">
                <input v-model="newOverrideForms[member.email + '_match']" placeholder="Match prefix (Z:\)" class="flex-1 px-2.5 py-1.5 rounded-lg bg-white dark:bg-surface-900 border border-surface-200 dark:border-surface-600 text-surface-700 dark:text-surface-200 text-xs outline-none focus:ring-2 focus:ring-primary-500/30 font-mono" />
                <input v-model="newOverrideForms[member.email + '_replace']" placeholder="Replace with (Y:\)" class="flex-1 px-2.5 py-1.5 rounded-lg bg-white dark:bg-surface-900 border border-surface-200 dark:border-surface-600 text-surface-700 dark:text-surface-200 text-xs outline-none focus:ring-2 focus:ring-primary-500/30 font-mono" />
                <input v-model="newOverrideForms[member.email + '_label']" placeholder="Label" class="w-24 px-2.5 py-1.5 rounded-lg bg-white dark:bg-surface-900 border border-surface-200 dark:border-surface-600 text-surface-700 dark:text-surface-200 text-xs outline-none focus:ring-2 focus:ring-primary-500/30" />
                <button @click="addTeamOverride(member.email)" :disabled="!newOverrideForms[member.email + '_match'] || !newOverrideForms[member.email + '_replace']" class="px-3 py-1.5 rounded-lg bg-indigo-500 text-white text-xs font-semibold disabled:opacity-50 hover:bg-indigo-600 transition-colors">Add</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import { useColleaguesStore } from '@/addons/team/stores/colleagues'
import api from '@/services/api'

const props = defineProps({
  boardId: { type: Number, default: null },
  clientId: { type: Number, default: null },
})

const hubStore = useProjectHubStore()
const colleaguesStore = useColleaguesStore()
const userIsAdmin = computed(() => colleaguesStore.isAdmin)

const activeTab = ref('folders')
const tabs = computed(() => {
  const items = [{ id: 'folders', label: 'Watch Folders', icon: 'folder_eye' }]
  if (userIsAdmin.value) items.push({ id: 'overrides', label: 'Path Overrides', icon: 'swap_horiz' })
  return items
})

const loading = ref(false)
const allFolders = ref([])
const teamStatus = ref([])
const clients = ref([])
const memberOverrides = reactive({})
const expandedMembers = ref(new Set())
const showCreateFolder = ref(false)
const newOverrideForms = reactive({})

const spaceClientId = computed(() => props.clientId || hubStore.activeSpace?.client_id || null)
const folderBoards = computed(() => hubStore.folderOverview?.boards || hubStore.activeFolder?.boards || [])
const folderForm = ref({ name: '', folder_path: '', client_id: null, board_id: null })

const displayFolders = computed(() => {
  if (props.boardId) return allFolders.value.filter(f => Number(f.board_id) === props.boardId)
  return allFolders.value
})

onMounted(async () => {
  loading.value = true
  try {
    const [foldersRes, clientsRes] = await Promise.all([
      api.get('/watch-folders'),
      api.get('/clients'),
    ])
    allFolders.value = foldersRes.data.data || []
    const clientList = clientsRes.data.data?.clients || clientsRes.data.data || []
    clients.value = clientList.map(c => ({ id: Number(c.id), name: c.display_name || c.domain || c.name }))
  } catch (e) {
    console.error('WatchFolderManager load error:', e)
  } finally {
    loading.value = false
  }

  if (userIsAdmin.value) {
    try {
      const statusRes = await api.get('/path-overrides/team-status')
      teamStatus.value = statusRes.data.data || []
    } catch {
      teamStatus.value = []
    }
  }
})

function openCreateDialog() {
  const boards = folderBoards.value
  folderForm.value = {
    name: '',
    folder_path: '',
    client_id: spaceClientId.value ? Number(spaceClientId.value) : null,
    board_id: props.boardId || (boards.length === 1 ? Number(boards[0].board_id || boards[0].id) : null),
  }
  showCreateFolder.value = true
}

async function createFolder() {
  try {
    const payload = { ...folderForm.value }
    if (!payload.board_id) delete payload.board_id
    await api.post('/watch-folders', payload)
    showCreateFolder.value = false
    folderForm.value = { name: '', folder_path: '', client_id: null, board_id: null }
    const res = await api.get('/watch-folders')
    allFolders.value = res.data.data || []
  } catch (e) {
    console.error('Create watch folder error:', e)
  }
}

async function deleteFolder(id) {
  try {
    await api.delete(`/watch-folders/${id}`)
    allFolders.value = allFolders.value.filter(f => f.id !== id)
  } catch (e) {
    console.error('Delete watch folder error:', e)
  }
}

async function toggleMember(email) {
  if (expandedMembers.value.has(email)) {
    expandedMembers.value.delete(email)
    return
  }
  expandedMembers.value.add(email)
  if (!memberOverrides[email]) {
    try {
      const res = await api.get('/path-overrides', { params: { user: email } })
      memberOverrides[email] = res.data.data || []
    } catch {
      memberOverrides[email] = []
    }
  }
}

async function addTeamOverride(email) {
  const matchPrefix = newOverrideForms[email + '_match']
  const replacePrefix = newOverrideForms[email + '_replace']
  const label = newOverrideForms[email + '_label'] || ''
  if (!matchPrefix || !replacePrefix) return

  try {
    await api.post('/path-overrides', { target_email: email, match_prefix: matchPrefix, replace_prefix: replacePrefix, label })
    const res = await api.get('/path-overrides', { params: { user: email } })
    memberOverrides[email] = res.data.data || []
    newOverrideForms[email + '_match'] = ''
    newOverrideForms[email + '_replace'] = ''
    newOverrideForms[email + '_label'] = ''
  } catch (e) {
    console.error('Add path override error:', e)
  }
}

async function deleteTeamOverride(email, overrideId) {
  try {
    await api.delete(`/path-overrides/${overrideId}`, { data: { target_email: email } })
    memberOverrides[email] = (memberOverrides[email] || []).filter(o => o.id !== overrideId)
  } catch (e) {
    console.error('Delete path override error:', e)
  }
}
</script>
