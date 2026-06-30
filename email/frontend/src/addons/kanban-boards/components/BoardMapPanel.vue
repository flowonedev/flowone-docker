<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useDriveStore } from '@/stores/drive'
import { useClientsStore } from '@/stores/clients'
import { useToastStore } from '@/stores/toast'
import ConfirmModal from '@/components/ConfirmModal.vue'
import SharePermissionsModal from './SharePermissionsModal.vue'
import TrackedWebsitesList from './TrackedWebsitesList.vue'
import { isDebugEnabled } from '@/utils/debug'
import api from '@/services/api'

const props = defineProps({
  boardId: {
    type: Number,
    required: true
  }
})

const router = useRouter()
const route = useRoute()
const boardsStore = useBoardsStore()
const driveStore = useDriveStore()
const clientsStore = useClientsStore()
const toast = useToastStore()

// Valid tabs
const validTabs = ['drive', 'emails', 'members', 'client', 'websites']

// Active tab - initialize from URL query or default to 'drive'
const getInitialTab = () => {
  const urlTab = route.query.tab
  return validTabs.includes(urlTab) ? urlTab : 'drive'
}
const activeTab = ref(getInitialTab())

// === DRIVE FOLDER STATE ===
const driveFolder = ref(null)
const folderContents = ref({ folders: [], files: [] })
const availableFolders = ref([])
const showLinkFolderModal = ref(false)
const selectedFolderId = ref(null)
const loadingFolder = ref(false)
const loadingFolders = ref(false)
const creatingBoardFolder = ref(false)
const folderMissing = ref(false)
const missingFolderName = ref('')

// === LINKED EMAILS STATE ===
const linkedEmails = ref([])
const loadingEmails = ref(false)

// === MEMBERS STATE ===
const memberToRemove = ref(null)
const showRemoveConfirm = ref(false)
const showShareModal = ref(false)
const editingMember = ref(null)

// === CLIENT STATE ===
const linkedClient = ref(null)
const availableClients = ref([])
const showLinkClientModal = ref(false)
const selectedClientId = ref(null)
const loadingClient = ref(false)
const loadingClients = ref(false)

// === TRACKED URLS STATE ===
const trackedUrls = ref([])
const loadingUrls = ref(false)

// === BOARD CHECK STATE ===
const showCheckModal = ref(false)
const checkResult = ref(null)
const loadingCheck = ref(false)

// Computed
const board = computed(() => boardsStore.currentBoard)
const members = computed(() => boardsStore.currentMembers || [])
const isOwner = computed(() => board.value?.user_role === 'owner')

// Tab counts
const counts = computed(() => ({
  drive: folderContents.value.folders.length + folderContents.value.files.length,
  emails: linkedEmails.value.length,
  members: members.value.length,
  client: linkedClient.value ? 1 : 0,
  websites: trackedUrls.value.length
}))

// === DRIVE FOLDER METHODS ===
async function loadDriveFolder() {
  loadingFolder.value = true
  folderMissing.value = false
  missingFolderName.value = ''
  
  try {
    // Get board's linked drive folder from the dedicated endpoint
    const response = await api.get(`/boards/${props.boardId}/drive-folder`)
    if (response.data.success) {
      // Check if folder is missing (link exists but folder was deleted)
      if (response.data.data.folder_missing) {
        folderMissing.value = true
        missingFolderName.value = response.data.data.missing_folder_name || 'Unknown'
        driveFolder.value = null
        folderContents.value = { folders: [], files: [] }
      } else if (response.data.data.folder) {
        driveFolder.value = response.data.data.folder
        // Also load folder contents
        await loadFolderContents(driveFolder.value.id)
      } else {
        driveFolder.value = null
        folderContents.value = { folders: [], files: [] }
      }
    } else {
      driveFolder.value = null
      folderContents.value = { folders: [], files: [] }
    }
  } catch (error) {
    console.error('Failed to load drive folder:', error)
    driveFolder.value = null
    folderContents.value = { folders: [], files: [] }
  } finally {
    loadingFolder.value = false
  }
}

async function loadFolderContents(folderId) {
  try {
    const response = await api.get(`/drive?folder_id=${folderId}`)
    if (response.data.success) {
      folderContents.value = {
        folders: response.data.data.folders || [],
        files: response.data.data.files || []
      }
    }
  } catch (error) {
    console.error('Failed to load folder contents:', error)
    folderContents.value = { folders: [], files: [] }
  }
}

async function loadAvailableFolders() {
  loadingFolders.value = true
  try {
    // Get all user folders for selection
    const allFolders = await driveStore.fetchAllFolders()
    availableFolders.value = allFolders || []
  } catch (error) {
    console.error('Failed to load folders:', error)
    availableFolders.value = []
  } finally {
    loadingFolders.value = false
  }
}

async function linkDriveFolder() {
  if (!selectedFolderId.value) return
  
  try {
    const response = await api.post(`/boards/${props.boardId}/drive-folder`, {
      folder_id: selectedFolderId.value
    })
    
    if (response.data.success) {
      toast.success('Drive folder linked')
      showLinkFolderModal.value = false
      selectedFolderId.value = null
      await boardsStore.fetchBoard(props.boardId)
      await loadDriveFolder()
    } else {
      toast.error(response.data.message || 'Failed to link folder')
    }
  } catch (error) {
    console.error('Failed to link folder:', error)
    toast.error('Failed to link folder')
  }
}

async function unlinkDriveFolder() {
  if (!confirm('Unlink this Drive folder from the board?')) return
  
  try {
    const response = await api.delete(`/boards/${props.boardId}/drive-folder`)
    
    if (response.data.success) {
      toast.success('Drive folder unlinked')
      driveFolder.value = null
      folderMissing.value = false
      missingFolderName.value = ''
      await boardsStore.fetchBoard(props.boardId)
    } else {
      toast.error('Failed to unlink folder')
    }
  } catch (error) {
    console.error('Failed to unlink folder:', error)
    toast.error('Failed to unlink folder')
  }
}

async function clearMissingFolderReference() {
  try {
    const response = await api.delete(`/boards/${props.boardId}/drive-folder`)
    
    if (response.data.success) {
      toast.success('Cleared missing folder reference')
      folderMissing.value = false
      missingFolderName.value = ''
      await boardsStore.fetchBoard(props.boardId)
    } else {
      toast.error('Failed to clear reference')
    }
  } catch (error) {
    console.error('Failed to clear missing folder reference:', error)
    toast.error('Failed to clear reference')
  }
}

async function createBoardFolder() {
  if (creatingBoardFolder.value) return  // Prevent duplicate calls
  
  creatingBoardFolder.value = true
  try {
    // Use the board-specific endpoint that creates AND links the folder
    const response = await api.post(`/boards/${props.boardId}/drive-folder`)
    
    if (response.data.success) {
      toast.success('Folder created and linked')
      driveFolder.value = response.data.data.folder
      await boardsStore.fetchBoard(props.boardId)
    } else {
      toast.error(response.data.message || 'Failed to create folder')
    }
  } catch (error) {
    console.error('Failed to create folder:', error)
    toast.error('Failed to create folder')
  } finally {
    creatingBoardFolder.value = false
  }
}

function openDriveFolder() {
  if (driveFolder.value?.id) {
    router.push(`/drive?folder=${driveFolder.value.id}`)
  }
}

// === LINKED EMAILS METHODS ===
async function loadLinkedEmails() {
  loadingEmails.value = true
  try {
    linkedEmails.value = await boardsStore.getBoardEmails(props.boardId) || []
  } catch (error) {
    console.error('Failed to load linked emails:', error)
    linkedEmails.value = []
  } finally {
    loadingEmails.value = false
  }
}

async function unlinkEmail(linkId) {
  const success = await boardsStore.unlinkEmailFromBoard(linkId)
  if (success) {
    linkedEmails.value = linkedEmails.value.filter(e => e.id !== linkId)
    toast.success('Email unlinked')
  }
}

function goToEmail(email) {
  const folderPath = (email.email_folder || 'INBOX')
    .replace(/\./g, '/')
    .replace(/ /g, '_')
    .toLowerCase()
  router.push(`/email/${folderPath}/message/${email.email_uid}`)
}

// === MEMBERS METHODS ===
function openAddMemberModal() {
  editingMember.value = null
  showShareModal.value = true
}

function openEditMemberModal(member) {
  if (member.is_owner) return
  editingMember.value = member
  showShareModal.value = true
}

async function handleSavePermissions({ email, role, permissions }) {
  const isEditing = !!editingMember.value
  
  try {
    if (isEditing) {
      const response = await api.post(`/boards/${props.boardId}/members/permissions`, {
        member_email: email,
        role,
        ...permissions
      })
      
      if (response.data.success) {
        toast.success('Permissions updated')
        await boardsStore.fetchBoard(props.boardId)
      } else {
        toast.error('Failed to update permissions')
      }
    } else {
      const response = await api.post(`/boards/${props.boardId}/members`, {
        email,
        role,
        ...permissions
      })
      
      if (response.data.success) {
        toast.success('Member added')
        await boardsStore.fetchBoard(props.boardId)
      } else {
        toast.error(response.data.message || 'Failed to add member')
      }
    }
    
    showShareModal.value = false
    editingMember.value = null
  } catch (e) {
    console.error('Failed to save permissions:', e)
    toast.error('Failed to save. Please try again.')
  }
}

// ONE HTTP call instead of N parallel adds; one board refresh.
async function handleSaveBulkPermissions({ emails, role, permissions }) {
  if (!Array.isArray(emails) || emails.length === 0) return

  try {
    const members = emails.map(email => ({ email, role, ...permissions }))
    const response = await api.post(`/boards/${props.boardId}/members/batch`, { members })
    const data = response.data?.data || {}
    const added = data.added || 0
    const failed = data.failed || 0
    if (added > 0) {
      toast.success(`Added ${added} member(s)`)
      await boardsStore.fetchBoard(props.boardId)
    }
    if (failed > 0) {
      toast.warning(`${failed} member(s) failed to add`)
    }
    showShareModal.value = false
    editingMember.value = null
  } catch (e) {
    console.error('Failed to bulk save permissions:', e)
    toast.error('Failed to save members. Please try again.')
  }
}

function confirmRemoveMember(member) {
  if (member.is_owner) return
  memberToRemove.value = member
  showRemoveConfirm.value = true
}

async function removeMember() {
  if (!memberToRemove.value) return
  
  showRemoveConfirm.value = false
  const member = memberToRemove.value
  memberToRemove.value = null
  
  const success = await boardsStore.removeMember(props.boardId, member.email)
  if (success) {
    toast.success('Member removed')
  } else {
    toast.error('Failed to remove member')
  }
}

function getRoleDescription(role) {
  const descriptions = {
    owner: 'Full access - can edit, delete, and manage members',
    editor: 'Can create, edit, and move cards',
    viewer: 'Can view board and cards only'
  }
  return descriptions[role] || ''
}

function getPermissionsCount(member) {
  let count = 0
  if (member.can_view_financials) count++
  if (member.can_view_client) count++
  if (member.can_view_contacts) count++
  if (member.can_view_emails) count++
  if (member.can_access_drive) count++
  return count
}

// === CLIENT METHODS ===
async function loadLinkedClient() {
  loadingClient.value = true
  try {
    // Check if board has a linked_client from client_boards table
    if (board.value?.linked_client) {
      linkedClient.value = board.value.linked_client
    } else {
      linkedClient.value = null
    }
  } catch (error) {
    console.error('Failed to load linked client:', error)
    linkedClient.value = null
  } finally {
    loadingClient.value = false
  }
}

async function loadAvailableClients() {
  loadingClients.value = true
  try {
    await clientsStore.fetchClients()
    availableClients.value = clientsStore.clients || []
  } catch (error) {
    console.error('Failed to load clients:', error)
    availableClients.value = []
  } finally {
    loadingClients.value = false
  }
}

async function linkClient() {
  if (!selectedClientId.value) return
  
  try {
    // Use the client_boards endpoint to link board to client
    const response = await api.post(`/clients/${selectedClientId.value}/boards`, {
      board_id: props.boardId
    })
    
    if (response.data.success) {
      toast.success('Client linked to board')
      showLinkClientModal.value = false
      selectedClientId.value = null
      await boardsStore.fetchBoard(props.boardId)
      await loadLinkedClient()
    } else {
      toast.error(response.data.message || 'Failed to link client')
    }
  } catch (error) {
    console.error('Failed to link client:', error)
    toast.error('Failed to link client')
  }
}

async function unlinkClient() {
  if (!confirm('Unlink this client from the board?')) return
  if (!linkedClient.value?.id) return
  
  try {
    // Use the client_boards endpoint to unlink
    const response = await api.delete(`/clients/${linkedClient.value.id}/boards/${props.boardId}`)
    
    if (response.data.success) {
      toast.success('Client unlinked')
      linkedClient.value = null
      await boardsStore.fetchBoard(props.boardId)
    } else {
      toast.error('Failed to unlink client')
    }
  } catch (error) {
    console.error('Failed to unlink client:', error)
    toast.error('Failed to unlink client')
  }
}

function goToClient() {
  if (linkedClient.value?.id) {
    router.push(`/clients/${linkedClient.value.id}`)
  }
}

// === HELPERS ===
function formatDate(dateString) {
  if (!dateString) return ''
  return new Date(dateString).toLocaleDateString(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric'
  })
}

// Load data based on active tab
// === TRACKED URLS METHODS ===
// === BOARD CHECK METHODS ===
async function checkBoard() {
  loadingCheck.value = true
  checkResult.value = null
  showCheckModal.value = true
  
  try {
    const response = await api.get(`/boards/${props.boardId}/check`)
    if (response.data.success) {
      checkResult.value = response.data.data
    } else {
      toast.error(response.data.error || 'Failed to check board')
      showCheckModal.value = false
    }
  } catch (e) {
    console.error('Check board error:', e)
    toast.error('Failed to check board')
    showCheckModal.value = false
  } finally {
    loadingCheck.value = false
  }
}

async function loadTrackedUrls() {
  loadingUrls.value = true
  try {
    const response = await api.get(`/boards/${props.boardId}/tracked-urls`)
    if (response.data.success) {
      trackedUrls.value = response.data.data.urls || []
    }
  } catch (error) {
    console.error('Failed to load tracked URLs:', error)
    trackedUrls.value = []
  } finally {
    loadingUrls.value = false
  }
}

async function loadTabData() {
  switch (activeTab.value) {
    case 'drive':
      await loadDriveFolder()
      break
    case 'emails':
      await loadLinkedEmails()
      break
    case 'members':
      // Members are already in boardsStore.currentMembers
      break
    case 'client':
      await loadLinkedClient()
      break
    case 'websites':
      await loadTrackedUrls()
      break
  }
}

function switchTab(tab) {
  if (!validTabs.includes(tab)) return
  activeTab.value = tab
  
  // Update URL without adding to history for same-page navigation feel
  // Use replace to avoid polluting browser history with tab changes
  router.replace({
    query: { ...route.query, tab }
  })
  
  loadTabData()
}

onMounted(() => {
  loadTabData()
})

// Watch for URL changes (back/forward navigation)
watch(() => route.query.tab, (newTab) => {
  if (newTab && validTabs.includes(newTab) && newTab !== activeTab.value) {
    activeTab.value = newTab
    loadTabData()
  }
})

watch(() => props.boardId, () => {
  loadTabData()
})
</script>

<template>
  <div class="h-full flex flex-col bg-white dark:bg-surface-800">
    <!-- Header -->
    <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <span class="material-symbols-rounded text-2xl text-primary-500">account_tree</span>
          <div>
            <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Board Map</h2>
            <p class="text-sm text-surface-500">Manage Drive folder, linked emails, and team members</p>
          </div>
        </div>
        <button 
          v-if="isOwner && isDebugEnabled()"
          @click="checkBoard"
          class="px-3 py-1.5 text-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors flex items-center gap-1.5"
          title="Check database info"
        >
          <span class="material-symbols-rounded text-lg">database</span>
          Check Board
        </button>
      </div>
    </div>
    
    <!-- Tabs -->
    <div class="border-b border-surface-200 dark:border-surface-700">
      <nav class="flex px-6 gap-1">
        <button
          @click="switchTab('drive')"
          :class="[
            'px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-all rounded-t-lg',
            activeTab === 'drive'
              ? 'border-primary-500 text-primary-600 dark:text-primary-400 bg-white dark:bg-surface-800'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'
          ]"
        >
          <span class="flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">folder</span>
            Drive Folder
            <span v-if="counts.drive > 0" class="w-2 h-2 bg-green-500 rounded-full"></span>
          </span>
        </button>
        
        <button
          @click="switchTab('emails')"
          :class="[
            'px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-all rounded-t-lg',
            activeTab === 'emails'
              ? 'border-primary-500 text-primary-600 dark:text-primary-400 bg-white dark:bg-surface-800'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'
          ]"
        >
          <span class="flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">attach_email</span>
            Linked Emails
            <span v-if="counts.emails > 0" class="px-1.5 py-0.5 text-xs bg-primary-500 text-white rounded-full min-w-[20px] text-center">
              {{ counts.emails }}
            </span>
          </span>
        </button>
        
        <button
          @click="switchTab('members')"
          :class="[
            'px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-all rounded-t-lg',
            activeTab === 'members'
              ? 'border-primary-500 text-primary-600 dark:text-primary-400 bg-white dark:bg-surface-800'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'
          ]"
        >
          <span class="flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">group</span>
            Members
            <span v-if="counts.members > 0" class="px-1.5 py-0.5 text-xs bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-400 rounded-full min-w-[20px] text-center">
              {{ counts.members }}
            </span>
          </span>
        </button>
        
        <button
          @click="switchTab('client')"
          :class="[
            'px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-all rounded-t-lg',
            activeTab === 'client'
              ? 'border-primary-500 text-primary-600 dark:text-primary-400 bg-white dark:bg-surface-800'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'
          ]"
        >
          <span class="flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">business</span>
            Client
            <span v-if="counts.client > 0" class="w-2 h-2 bg-green-500 rounded-full"></span>
          </span>
        </button>
        
        <button
          @click="switchTab('websites')"
          :class="[
            'px-4 py-3 text-sm font-medium border-b-2 -mb-px transition-all rounded-t-lg',
            activeTab === 'websites'
              ? 'border-primary-500 text-primary-600 dark:text-primary-400 bg-white dark:bg-surface-800'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-800'
          ]"
        >
          <span class="flex items-center gap-2">
            <span class="material-symbols-rounded text-lg">language</span>
            Tracked Websites
            <span v-if="counts.websites > 0" class="px-1.5 py-0.5 text-xs bg-cyan-500 text-white rounded-full min-w-[20px] text-center">
              {{ counts.websites }}
            </span>
          </span>
        </button>
      </nav>
    </div>
    
    <!-- Content -->
    <div class="flex-1 overflow-y-auto">
      <!-- Drive Folder Tab -->
      <div v-if="activeTab === 'drive'" class="p-6">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Linked Drive Folder</h3>
            <p class="text-sm text-surface-500 mt-0.5">Store files related to this board</p>
          </div>
          <button
            v-if="!driveFolder"
            @click="showLinkFolderModal = true; loadAvailableFolders()"
            class="flex items-center gap-2 px-4 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-xl transition-colors font-medium"
          >
            <span class="material-symbols-rounded">link</span>
            Link Folder
          </button>
        </div>
        
        <!-- Loading -->
        <div v-if="loadingFolder" class="flex items-center justify-center py-16">
          <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
        </div>
        
        <!-- Missing folder warning -->
        <div v-else-if="folderMissing" class="space-y-4">
          <div class="p-4 rounded-xl bg-red-50 dark:bg-red-500/10 border-2 border-red-300 dark:border-red-500/30">
            <div class="flex items-start gap-4">
              <div class="w-12 h-12 rounded-lg bg-red-100 dark:bg-red-500/20 flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-rounded text-2xl text-red-500">folder_delete</span>
              </div>
              <div class="flex-1 min-w-0">
                <h4 class="font-semibold text-red-700 dark:text-red-400">
                  Linked folder is missing
                </h4>
                <p class="text-sm text-red-600 dark:text-red-400/80 mt-1">
                  The folder "<strong>{{ missingFolderName }}</strong>" was deleted from Drive but is still referenced by this board.
                </p>
                <div class="flex flex-wrap gap-2 mt-4">
                  <button
                    @click="clearMissingFolderReference(); showLinkFolderModal = true; loadAvailableFolders()"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg transition-colors text-sm font-medium"
                  >
                    <span class="material-symbols-rounded text-lg">link</span>
                    Link Different Folder
                  </button>
                  <button
                    @click="clearMissingFolderReference"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-surface-200 dark:bg-surface-600 hover:bg-surface-300 dark:hover:bg-surface-500 text-surface-700 dark:text-surface-200 rounded-lg transition-colors text-sm font-medium"
                  >
                    <span class="material-symbols-rounded text-lg">link_off</span>
                    Clear Reference
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Linked folder display -->
        <div v-else-if="driveFolder" class="space-y-4">
          <div 
            class="p-4 rounded-xl bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 hover:border-primary-300 dark:hover:border-primary-600 transition-all cursor-pointer group"
            @click="openDriveFolder"
          >
            <div class="flex items-center gap-4">
              <div class="w-12 h-12 rounded-lg bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-2xl text-primary-500">folder</span>
              </div>
              <div class="flex-1 min-w-0">
                <h4 class="font-medium text-surface-900 dark:text-surface-100 group-hover:text-primary-500 transition-colors">
                  {{ driveFolder.name }}
                </h4>
                <p class="text-sm text-surface-500 mt-0.5">
                  {{ folderContents.folders.length }} folders, {{ folderContents.files.length }} files
                </p>
              </div>
              <span class="material-symbols-rounded text-xl text-surface-400 group-hover:text-primary-500 transition-colors">open_in_new</span>
            </div>
          </div>
        </div>
        
        <!-- Empty state -->
        <div v-else class="text-center py-16">
          <div class="w-20 h-20 mx-auto mb-4 bg-surface-100 dark:bg-surface-700 rounded-2xl flex items-center justify-center">
            <span class="material-symbols-rounded text-4xl text-surface-400">folder_off</span>
          </div>
          <h3 class="text-lg font-medium text-surface-900 dark:text-surface-100 mb-2">No Drive folder linked</h3>
          <p class="text-surface-500 mb-6 max-w-sm mx-auto">
            Link or create a folder to store files, attachments, and documents for this board.
          </p>
          <div class="flex flex-col sm:flex-row gap-3 justify-center">
            <button
              @click="showLinkFolderModal = true; loadAvailableFolders()"
              class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-xl transition-colors font-medium"
            >
              <span class="material-symbols-rounded">link</span>
              Link Existing Folder
            </button>
            <button
              @click="createBoardFolder"
              :disabled="creatingBoardFolder"
              class="inline-flex items-center justify-center gap-2 px-5 py-2.5 border-2 border-dashed border-surface-300 dark:border-surface-600 hover:border-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 text-surface-600 dark:text-surface-400 hover:text-primary-600 rounded-xl transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <span v-if="creatingBoardFolder" class="material-symbols-rounded animate-spin">progress_activity</span>
              <span v-else class="material-symbols-rounded">create_new_folder</span>
              {{ creatingBoardFolder ? 'Creating...' : `Create "${board?.name}" Folder` }}
            </button>
          </div>
        </div>
      </div>
      
      <!-- Linked Emails Tab -->
      <div v-if="activeTab === 'emails'" class="p-6">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Linked Emails</h3>
            <p class="text-sm text-surface-500 mt-0.5">Emails connected to this board</p>
          </div>
        </div>
        
        <!-- Loading -->
        <div v-if="loadingEmails" class="flex items-center justify-center py-16">
          <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
        </div>
        
        <!-- Email list -->
        <div v-else-if="linkedEmails.length > 0" class="space-y-3">
          <div 
            v-for="email in linkedEmails" 
            :key="email.id"
            class="p-4 rounded-xl bg-surface-50 dark:bg-surface-700 hover:bg-surface-100 dark:hover:bg-surface-650 transition-colors group border border-surface-200 dark:border-surface-600"
          >
            <div class="flex items-start gap-4">
              <!-- Email icon -->
              <div class="w-10 h-10 rounded-lg bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-rounded text-primary-500">email</span>
              </div>
              
              <!-- Content -->
              <div class="flex-1 min-w-0">
                <div 
                  class="cursor-pointer"
                  @click="goToEmail(email)"
                >
                  <h4 class="font-medium text-surface-900 dark:text-surface-100 line-clamp-1 hover:text-primary-500 transition-colors">
                    {{ email.email_subject || 'No subject' }}
                  </h4>
                  <p class="text-sm text-surface-600 dark:text-surface-400 mt-0.5 truncate">
                    From: {{ email.email_from || 'Unknown sender' }}
                  </p>
                  <p class="text-xs text-surface-400 mt-1">
                    Linked {{ formatDate(email.created_at) }}
                  </p>
                </div>
              </div>
              
              <!-- Actions -->
              <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                <button 
                  @click="goToEmail(email)"
                  class="p-2 rounded-lg text-surface-400 hover:text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 transition-all"
                  title="Open email"
                >
                  <span class="material-symbols-rounded">open_in_new</span>
                </button>
                <button 
                  @click="unlinkEmail(email.id)"
                  class="p-2 rounded-lg text-surface-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 transition-all"
                  title="Unlink email"
                >
                  <span class="material-symbols-rounded">link_off</span>
                </button>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Empty state -->
        <div v-else class="text-center py-16">
          <div class="w-20 h-20 mx-auto mb-4 bg-surface-100 dark:bg-surface-700 rounded-2xl flex items-center justify-center">
            <span class="material-symbols-rounded text-4xl text-surface-400">mail_outline</span>
          </div>
          <h3 class="text-lg font-medium text-surface-900 dark:text-surface-100 mb-2">No emails linked yet</h3>
          <p class="text-surface-500 max-w-sm mx-auto">
            Link emails from your inbox to track conversations and context for this board.
          </p>
        </div>
        
        <!-- Footer info -->
        <div class="mt-6 p-4 rounded-xl bg-surface-50 dark:bg-surface-700/50 border border-surface-200 dark:border-surface-600">
          <div class="flex items-center gap-2 text-sm text-surface-500">
            <span class="material-symbols-rounded text-lg">info</span>
            <span>Link emails from the Email view using the board link button</span>
          </div>
        </div>
      </div>
      
      <!-- Members Tab -->
      <div v-if="activeTab === 'members'" class="p-6">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Board Members</h3>
            <p class="text-sm text-surface-500 mt-0.5">Manage who has access to this board</p>
          </div>
          <button
            v-if="isOwner"
            @click="openAddMemberModal"
            class="flex items-center gap-2 px-4 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-xl transition-colors font-medium"
          >
            <span class="material-symbols-rounded">person_add</span>
            Add Member
          </button>
        </div>
        
        <!-- Members list -->
        <div class="space-y-3">
          <div 
            v-for="member in members"
            :key="member.email"
            @click="isOwner && !member.is_owner ? openEditMemberModal(member) : null"
            :class="[
              'flex items-center gap-4 p-4 rounded-xl border transition-all',
              isOwner && !member.is_owner 
                ? 'bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600 hover:border-primary-300 dark:hover:border-primary-600 cursor-pointer' 
                : 'bg-surface-50 dark:bg-surface-700 border-surface-200 dark:border-surface-600'
            ]"
          >
            <!-- Avatar -->
            <div 
              :class="[
                'w-12 h-12 rounded-full flex items-center justify-center text-white font-semibold text-lg uppercase shrink-0',
                member.is_owner ? 'bg-amber-500' : 'bg-primary-500'
              ]"
            >
              {{ member.email.charAt(0) }}
            </div>
            
            <!-- Info -->
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="font-medium text-surface-900 dark:text-surface-100 truncate">
                  {{ member.email }}
                </span>
                <span 
                  v-if="member.is_owner"
                  class="px-2 py-0.5 bg-amber-500/20 text-amber-600 dark:text-amber-400 text-xs font-medium rounded-full"
                >
                  Owner
                </span>
                <span 
                  v-else
                  class="px-2 py-0.5 bg-primary-500/20 text-primary-600 dark:text-primary-400 text-xs font-medium rounded-full capitalize"
                >
                  {{ member.role }}
                </span>
              </div>
              <div class="flex items-center gap-3 mt-1 flex-wrap">
                <p class="text-xs text-surface-500">
                  {{ getRoleDescription(member.role) }}
                </p>
                <!-- Permission badges -->
                <div v-if="!member.is_owner && getPermissionsCount(member) > 0" class="flex items-center gap-1">
                  <span v-if="member.can_view_financials" class="text-green-500" title="Can view financials">
                    <span class="material-symbols-rounded text-sm">payments</span>
                  </span>
                  <span v-if="member.can_view_client" class="text-blue-500" title="Can view client info">
                    <span class="material-symbols-rounded text-sm">business</span>
                  </span>
                  <span v-if="member.can_view_contacts" class="text-purple-500" title="Can view contacts">
                    <span class="material-symbols-rounded text-sm">contacts</span>
                  </span>
                  <span v-if="member.can_view_emails" class="text-amber-500" title="Can view linked emails">
                    <span class="material-symbols-rounded text-sm">email</span>
                  </span>
                  <span v-if="member.can_access_drive" class="text-cyan-500" title="Can access Drive folder">
                    <span class="material-symbols-rounded text-sm">folder</span>
                  </span>
                </div>
              </div>
            </div>
            
            <!-- Actions -->
            <div v-if="isOwner && !member.is_owner" class="flex items-center gap-1">
              <button 
                @click.stop="openEditMemberModal(member)"
                class="p-2 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-lg text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 transition-colors"
                title="Edit permissions"
              >
                <span class="material-symbols-rounded">settings</span>
              </button>
              <button 
                @click.stop="confirmRemoveMember(member)"
                class="p-2 hover:bg-red-500/20 rounded-lg text-surface-500 hover:text-red-500 transition-colors"
                title="Remove member"
              >
                <span class="material-symbols-rounded">person_remove</span>
              </button>
            </div>
          </div>
        </div>
        
        <!-- Empty state -->
        <div v-if="members.length <= 1" class="text-center py-12 mt-4">
          <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600 mb-3">group_add</span>
          <p class="text-sm text-surface-500 mb-4">
            No other members yet. Add members to collaborate on this board.
          </p>
          <button
            v-if="isOwner"
            @click="openAddMemberModal"
            class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium rounded-xl transition-colors inline-flex items-center gap-2"
          >
            <span class="material-symbols-rounded text-lg">person_add</span>
            <span>Add First Member</span>
          </button>
        </div>
        
        <!-- Permissions legend -->
        <div v-if="isOwner && members.length > 1" class="mt-6 p-4 rounded-xl bg-surface-50 dark:bg-surface-700/50 border border-surface-200 dark:border-surface-600">
          <h4 class="text-sm font-semibold text-surface-900 dark:text-surface-100 mb-3 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-primary-500">info</span>
            Permission Icons
          </h4>
          <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-xs">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-green-500">payments</span>
              <span class="text-surface-600 dark:text-surface-400">Financials</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-blue-500">business</span>
              <span class="text-surface-600 dark:text-surface-400">Client Info</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-purple-500">contacts</span>
              <span class="text-surface-600 dark:text-surface-400">Contacts</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-amber-500">email</span>
              <span class="text-surface-600 dark:text-surface-400">Linked Emails</span>
            </div>
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-cyan-500">folder</span>
              <span class="text-surface-600 dark:text-surface-400">Drive Folder</span>
            </div>
          </div>
          <p class="mt-3 text-xs text-surface-500">Click on a member to edit their permissions.</p>
        </div>
      </div>
      
      <!-- Client Tab -->
      <div v-if="activeTab === 'client'" class="p-6">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h3 class="font-semibold text-surface-900 dark:text-surface-100">Linked Client</h3>
            <p class="text-sm text-surface-500 mt-0.5">Associate this board with a client</p>
          </div>
          <button
            v-if="!linkedClient"
            @click="showLinkClientModal = true; loadAvailableClients()"
            class="flex items-center gap-2 px-4 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-xl transition-colors font-medium"
          >
            <span class="material-symbols-rounded">link</span>
            Link Client
          </button>
        </div>
        
        <!-- Loading -->
        <div v-if="loadingClient" class="flex items-center justify-center py-16">
          <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
        </div>
        
        <!-- Linked client display -->
        <div v-else-if="linkedClient" class="space-y-4">
          <div 
            class="p-4 rounded-xl bg-surface-50 dark:bg-surface-700 border border-surface-200 dark:border-surface-600 hover:border-primary-300 dark:hover:border-primary-600 transition-all cursor-pointer group"
            @click="goToClient"
          >
            <div class="flex items-center gap-4">
              <!-- Client avatar -->
              <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-2xl text-blue-500">business</span>
              </div>
              <div class="flex-1 min-w-0">
                <h4 class="font-medium text-surface-900 dark:text-surface-100 group-hover:text-primary-500 transition-colors">
                  {{ linkedClient.display_name }}
                </h4>
                <p v-if="linkedClient.domain" class="text-sm text-surface-500 mt-0.5">
                  {{ linkedClient.domain }}
                </p>
              </div>
              <span class="material-symbols-rounded text-xl text-surface-400 group-hover:text-primary-500 transition-colors">open_in_new</span>
            </div>
          </div>
          
          <!-- Actions -->
          <div class="flex gap-2">
            <button
              @click="goToClient"
              class="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 rounded-xl text-surface-700 dark:text-surface-300 transition-colors font-medium"
            >
              <span class="material-symbols-rounded">open_in_new</span>
              View Client
            </button>
            <button
              @click="unlinkClient"
              class="flex items-center justify-center gap-2 px-4 py-2.5 text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 rounded-xl transition-colors font-medium"
            >
              <span class="material-symbols-rounded">link_off</span>
              Unlink
            </button>
          </div>
        </div>
        
        <!-- Empty state -->
        <div v-else class="text-center py-16">
          <div class="w-20 h-20 mx-auto mb-4 bg-surface-100 dark:bg-surface-700 rounded-2xl flex items-center justify-center">
            <span class="material-symbols-rounded text-4xl text-surface-400">person_off</span>
          </div>
          <h3 class="text-lg font-medium text-surface-900 dark:text-surface-100 mb-2">No client linked</h3>
          <p class="text-surface-500 mb-6 max-w-sm mx-auto">
            Link this board to a client to track work, billing, and communications.
          </p>
          <button
            @click="showLinkClientModal = true; loadAvailableClients()"
            class="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-xl transition-colors font-medium"
          >
            <span class="material-symbols-rounded">link</span>
            Link Client
          </button>
        </div>
        
        <!-- Info box -->
        <div class="mt-6 p-4 rounded-xl bg-surface-50 dark:bg-surface-700/50 border border-surface-200 dark:border-surface-600">
          <div class="flex items-start gap-2 text-sm text-surface-500">
            <span class="material-symbols-rounded text-lg mt-0.5">info</span>
            <div>
              <p>Linking a client allows you to:</p>
              <ul class="mt-2 space-y-1 text-xs">
                <li class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm text-green-500">check</span>
                  Track time and billing for this board
                </li>
                <li class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm text-green-500">check</span>
                  Access client's Drive folder
                </li>
                <li class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-sm text-green-500">check</span>
                  View client info from the board
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Websites Tab -->
      <TrackedWebsitesList v-if="activeTab === 'websites'" :board-id="props.boardId" />
    </div>
    
    <!-- Link Folder Modal -->
    <div v-if="showLinkFolderModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @mousedown.self="showLinkFolderModal = false">
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
        <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700">
          <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">folder</span>
            Link Drive Folder
          </h3>
        </div>
        
        <div class="p-6">
          <div v-if="loadingFolders" class="flex items-center justify-center py-8">
            <span class="material-symbols-rounded text-2xl text-primary-500 animate-spin">progress_activity</span>
          </div>
          
          <div v-else-if="availableFolders.length > 0" class="max-h-64 overflow-y-auto border border-surface-200 dark:border-surface-700 rounded-xl">
            <button
              v-for="folder in availableFolders"
              :key="folder.id"
              @click="selectedFolderId = folder.id"
              :class="[
                'w-full flex items-center gap-3 px-4 py-3 hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors',
                selectedFolderId === folder.id ? 'bg-primary-50 dark:bg-primary-500/10' : ''
              ]"
            >
              <span class="material-symbols-rounded text-xl text-cyan-500">folder</span>
              <span class="flex-1 text-left font-medium text-surface-900 dark:text-surface-100 truncate">{{ folder.name }}</span>
              <span v-if="selectedFolderId === folder.id" class="material-symbols-rounded text-primary-500">check</span>
            </button>
          </div>
          
          <p v-else class="text-center py-8 text-surface-500">No folders available. Create one first in Drive.</p>
        </div>
        
        <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex gap-2 justify-end bg-surface-50 dark:bg-surface-900">
          <button
            @click="showLinkFolderModal = false; selectedFolderId = null"
            class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-xl transition-colors font-medium"
          >
            Cancel
          </button>
          <button
            @click="linkDriveFolder"
            :disabled="!selectedFolderId"
            class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-xl transition-colors disabled:opacity-50 disabled:cursor-not-allowed font-medium"
          >
            Link Folder
          </button>
        </div>
      </div>
    </div>
    
    <!-- Link Client Modal -->
    <div v-if="showLinkClientModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @mousedown.self="showLinkClientModal = false">
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
        <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700">
          <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
            <span class="material-symbols-rounded text-blue-500">business</span>
            Link Client
          </h3>
        </div>
        
        <div class="p-6">
          <div v-if="loadingClients" class="flex items-center justify-center py-8">
            <span class="material-symbols-rounded text-2xl text-primary-500 animate-spin">progress_activity</span>
          </div>
          
          <div v-else-if="availableClients.length > 0" class="max-h-64 overflow-y-auto border border-surface-200 dark:border-surface-700 rounded-xl">
            <button
              v-for="client in availableClients"
              :key="client.id"
              @click="selectedClientId = client.id"
              :class="[
                'w-full flex items-center gap-3 px-4 py-3 hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors',
                selectedClientId === client.id ? 'bg-primary-50 dark:bg-primary-500/10' : ''
              ]"
            >
              <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-rounded text-lg text-blue-500">business</span>
              </div>
              <div class="flex-1 text-left min-w-0">
                <span class="font-medium text-surface-900 dark:text-surface-100 truncate block">{{ client.display_name }}</span>
                <span v-if="client.domain" class="text-xs text-surface-500 truncate block">{{ client.domain }}</span>
              </div>
              <span v-if="selectedClientId === client.id" class="material-symbols-rounded text-primary-500">check</span>
            </button>
          </div>
          
          <div v-else class="text-center py-8">
            <p class="text-surface-500 mb-4">No clients available. Create one first.</p>
            <button
              @click="showLinkClientModal = false; router.push('/clients')"
              class="inline-flex items-center gap-2 px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-xl transition-colors font-medium"
            >
              <span class="material-symbols-rounded">add</span>
              Create Client
            </button>
          </div>
        </div>
        
        <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex gap-2 justify-end bg-surface-50 dark:bg-surface-900">
          <button
            @click="showLinkClientModal = false; selectedClientId = null"
            class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-xl transition-colors font-medium"
          >
            Cancel
          </button>
          <button
            @click="linkClient"
            :disabled="!selectedClientId"
            class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-xl transition-colors disabled:opacity-50 disabled:cursor-not-allowed font-medium"
          >
            Link Client
          </button>
        </div>
      </div>
    </div>
    
    <!-- Share Permissions Modal -->
    <SharePermissionsModal
      :show="showShareModal"
      :member="editingMember"
      :board-id="boardId"
      :is-editing="!!editingMember"
      @close="showShareModal = false; editingMember = null"
      @save="handleSavePermissions"
      @save-bulk="handleSaveBulkPermissions"
    />
    
    <!-- Remove Member Confirm Modal -->
    <ConfirmModal
      :show="showRemoveConfirm"
      title="Remove Member"
      :message="`Remove ${memberToRemove?.email} from this board? They will lose access immediately.`"
      confirm-text="Remove"
      :danger="true"
      @confirm="removeMember"
      @cancel="showRemoveConfirm = false; memberToRemove = null"
    />
    
    <!-- Check Board Modal -->
    <Teleport to="body">
      <div 
        v-if="showCheckModal" 
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        @click.self="showCheckModal = false"
      >
        <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] flex flex-col">
          <!-- Header -->
          <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-xl text-primary-500">database</span>
              <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Board Database Check</h3>
            </div>
            <button 
              @click="showCheckModal = false"
              class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
            >
              <span class="material-symbols-rounded text-xl text-surface-500">close</span>
            </button>
          </div>
          
          <!-- Content -->
          <div class="flex-1 overflow-y-auto p-6 space-y-6">
            <!-- Loading -->
            <div v-if="loadingCheck" class="flex items-center justify-center py-12">
              <span class="material-symbols-rounded text-4xl text-primary-500 animate-spin">progress_activity</span>
            </div>
            
            <!-- Results -->
            <template v-else-if="checkResult">
              <!-- Board Info -->
              <div class="space-y-2">
                <h4 class="text-sm font-semibold text-surface-700 dark:text-surface-300 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">dashboard</span>
                  Board Info
                </h4>
                <div class="bg-surface-50 dark:bg-surface-900 rounded-xl p-4 text-sm space-y-1 font-mono">
                  <div><span class="text-surface-500">ID:</span> {{ checkResult.board?.id }}</div>
                  <div><span class="text-surface-500">Name:</span> {{ checkResult.board?.name }}</div>
                  <div><span class="text-surface-500">Owner:</span> {{ checkResult.board?.owner_email }}</div>
                  <div><span class="text-surface-500">Client ID:</span> {{ checkResult.board?.client_id || 'None' }}</div>
                  <div><span class="text-surface-500">Drive Folder ID:</span> {{ checkResult.board?.drive_folder_id || 'None' }}</div>
                  <div><span class="text-surface-500">Created:</span> {{ checkResult.board?.created_at }}</div>
                </div>
              </div>
              
              <!-- Members -->
              <div class="space-y-2">
                <h4 class="text-sm font-semibold text-surface-700 dark:text-surface-300 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">group</span>
                  Members ({{ checkResult.members?.length || 0 }})
                </h4>
                <div v-if="checkResult.members?.length" class="bg-surface-50 dark:bg-surface-900 rounded-xl p-4 text-sm space-y-2 font-mono">
                  <div v-for="m in checkResult.members" :key="m.user_email" class="border-b border-surface-200 dark:border-surface-700 pb-2 last:border-0 last:pb-0">
                    <div class="font-medium">{{ m.user_email }} ({{ m.role }})</div>
                    <div class="text-xs text-surface-500">
                      Permissions: financials={{ m.can_view_financials }}, client={{ m.can_view_client }}, contacts={{ m.can_view_contacts }}, emails={{ m.can_view_emails }}, drive={{ m.can_access_drive }}
                    </div>
                  </div>
                </div>
                <div v-else class="text-sm text-surface-500 italic">No members (only owner)</div>
              </div>
              
              <!-- Linked Clients -->
              <div class="space-y-2">
                <h4 class="text-sm font-semibold text-surface-700 dark:text-surface-300 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">business</span>
                  Linked Clients ({{ checkResult.linked_clients?.length || 0 }})
                </h4>
                <div v-if="checkResult.linked_clients?.length" class="bg-surface-50 dark:bg-surface-900 rounded-xl p-4 text-sm space-y-1 font-mono">
                  <div v-for="c in checkResult.linked_clients" :key="c.id">
                    [{{ c.id }}] {{ c.domain }} <span v-if="c.display_name">({{ c.display_name }})</span> - {{ c.status }}
                  </div>
                </div>
                <div v-else class="text-sm text-surface-500 italic">No linked clients</div>
              </div>
              
              <!-- Direct Client -->
              <div v-if="checkResult.direct_client" class="space-y-2">
                <h4 class="text-sm font-semibold text-surface-700 dark:text-surface-300 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">person</span>
                  Direct Client (board.client_id)
                </h4>
                <div class="bg-surface-50 dark:bg-surface-900 rounded-xl p-4 text-sm font-mono">
                  [{{ checkResult.direct_client.id }}] {{ checkResult.direct_client.domain }} 
                  <span v-if="checkResult.direct_client.display_name">({{ checkResult.direct_client.display_name }})</span>
                  - {{ checkResult.direct_client.status }}
                </div>
              </div>
              
              <!-- Tracked URLs -->
              <div class="space-y-2">
                <h4 class="text-sm font-semibold text-surface-700 dark:text-surface-300 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">language</span>
                  Tracked Websites ({{ checkResult.tracked_urls?.length || 0 }})
                </h4>
                <div v-if="checkResult.tracked_urls?.length" class="bg-surface-50 dark:bg-surface-900 rounded-xl p-4 text-sm space-y-1 font-mono">
                  <div v-for="u in checkResult.tracked_urls" :key="u.id">
                    [{{ u.id }}] {{ u.url_domain }} 
                    <span v-if="u.display_name">({{ u.display_name }})</span>
                    - {{ u.is_active ? 'active' : 'inactive' }}, client_id={{ u.client_id }}
                  </div>
                </div>
                <div v-else class="text-sm text-surface-500 italic">No tracked websites</div>
              </div>
              
              <!-- Drive Folder -->
              <div class="space-y-2">
                <h4 class="text-sm font-semibold text-surface-700 dark:text-surface-300 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">folder</span>
                  Drive Folder
                </h4>
                <div v-if="checkResult.drive_folder" class="bg-surface-50 dark:bg-surface-900 rounded-xl p-4 text-sm font-mono space-y-1">
                  <template v-if="checkResult.drive_folder.error">
                    <div class="text-red-500">{{ checkResult.drive_folder.error }}</div>
                  </template>
                  <template v-else>
                    <div><span class="text-surface-500">ID:</span> {{ checkResult.drive_folder.id }}</div>
                    <div><span class="text-surface-500">Name:</span> {{ checkResult.drive_folder.name }}</div>
                    <div><span class="text-surface-500">Parent ID:</span> {{ checkResult.drive_folder.parent_id || 'None (root)' }}</div>
                    <div><span class="text-surface-500">Owner:</span> {{ checkResult.drive_folder.owner }}</div>
                    <div><span class="text-surface-500">Contents:</span> {{ checkResult.drive_folder.subfolders }} subfolders, {{ checkResult.drive_folder.files }} files</div>
                  </template>
                </div>
                <div v-else class="text-sm text-surface-500 italic">No drive folder linked</div>
              </div>
              
              <!-- Linked Emails Count -->
              <div class="space-y-2">
                <h4 class="text-sm font-semibold text-surface-700 dark:text-surface-300 flex items-center gap-2">
                  <span class="material-symbols-rounded text-lg">email</span>
                  Linked Emails
                </h4>
                <div class="bg-surface-50 dark:bg-surface-900 rounded-xl p-4 text-sm font-mono">
                  Total: {{ checkResult.linked_emails_count }} emails linked
                </div>
              </div>
            </template>
          </div>
          
          <!-- Footer -->
          <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex justify-end">
            <button 
              @click="showCheckModal = false"
              class="px-4 py-2 bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300 rounded-xl transition-colors font-medium"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.line-clamp-1 {
  display: -webkit-box;
  -webkit-line-clamp: 1;
  line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.animate-spin {
  animation: spin 1s linear infinite;
}
</style>
