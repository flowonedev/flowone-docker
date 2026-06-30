<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useFiltersStore } from '@/stores/filters'
import { useMailboxStore } from '@/stores/mailbox'
import { useLabelsStore } from '@/stores/labels'
import { useToastStore } from '@/stores/toast'
import { useAccountsStore } from '@/stores/accounts'
import ConfirmModal from './shared/ConfirmModal.vue'
import FilterModal from './FilterModal.vue'

const route = useRoute()
const filtersStore = useFiltersStore()
const mailbox = useMailboxStore()
const labelsStore = useLabelsStore()
const toast = useToastStore()
const accountsStore = useAccountsStore()

// Check if current account is OAuth (no Sieve available for OAuth)
const isOAuthAccount = computed(() => {
  const activeAccount = accountsStore.activeAccount
  return activeAccount?.auth_type === 'oauth'
})

// Filter modal state (uses shared FilterModal component)
const showFilterModal = ref(false)
const editingFilter = ref(null)
const showDeleteConfirm = ref(false)
const filterToDelete = ref(null)
const showApplyModal = ref(false)
const applyResults = ref(null)
const applyTargetFolder = ref('INBOX')
const applyMessageLimit = ref(100)

// Search/filter
const searchQuery = ref('')

// Progress tracking for continuous filter application
const isRunning = ref(false)
const isCancelled = ref(false)
const progressStats = ref({
  totalProcessed: 0,
  totalMatched: 0,
  allActions: [],
  batchesCompleted: 0,
  folderTotal: 0,
  totalPages: 1,
  currentPage: 1,
  isComplete: false
})

// Last run tracking
const lastRunInfo = ref(null)

// Create folder state
const showCreateFolder = ref(false)
const newFolderName = ref('')
const newFolderParent = ref('')
const creatingFolder = ref(false)

const parentFolderOptions = computed(() => {
  return filteredFolders.value.filter(f => f.name !== 'INBOX' && !f.name.startsWith('INBOX.'))
})

async function createNewFolder() {
  if (!newFolderName.value.trim()) return
  creatingFolder.value = true
  try {
    const parent = newFolderParent.value || null
    await mailbox.createFolder(newFolderName.value.trim(), parent)
    toast.success(`Folder "${newFolderName.value}" created`)
    showCreateFolder.value = false
    newFolderName.value = ''
    newFolderParent.value = ''
  } catch (e) {
    toast.error('Failed to create folder')
  } finally {
    creatingFolder.value = false
  }
}

// Create label state
const showCreateLabel = ref(false)
const newLabelName = ref('')
const newLabelColor = ref('#3b82f6')
const creatingLabel = ref(false)
const labelColorPresets = ['#3b82f6', '#ef4444', '#f59e0b', '#10b981', '#8b5cf6', '#ec4899', '#06b6d4', '#f97316', '#6366f1', '#14b8a6']

async function createNewLabel() {
  if (!newLabelName.value.trim()) return
  creatingLabel.value = true
  try {
    await labelsStore.createLabel(newLabelName.value.trim(), newLabelColor.value)
    toast.success(`Label "${newLabelName.value}" created`)
    showCreateLabel.value = false
    newLabelName.value = ''
    newLabelColor.value = '#3b82f6'
  } catch (e) {
    toast.error('Failed to create label')
  } finally {
    creatingLabel.value = false
  }
}

// Single filter run modal
const showSingleRunModal = ref(false)
const singleRunFilter = ref(null)
const singleRunFolder = ref('INBOX')
const singleRunAllFolders = ref(false)
const singleRunCurrentFolderName = ref('') // Track current folder being processed
const singleRunning = ref(false)
const singleRunCancelled = ref(false)
const singleRunResults = ref(null)
const singleRunProgress = ref({
  totalProcessed: 0,
  totalMatched: 0,
  allActions: [],
  batchesCompleted: 0,
  folderTotal: 0,
  totalPages: 1,
  currentPage: 1
})


function loadLastRunInfo() {
  try {
    const stored = localStorage.getItem('filterLastRun')
    if (stored) {
      lastRunInfo.value = JSON.parse(stored)
    }
  } catch (e) {
    console.error('Failed to load last run info:', e)
  }
}

function saveLastRunInfo(info) {
  lastRunInfo.value = info
  try {
    localStorage.setItem('filterLastRun', JSON.stringify(info))
  } catch (e) {
    console.error('Failed to save last run info:', e)
  }
}

function formatLastRunTime(timestamp) {
  if (!timestamp) return ''
  const date = new Date(timestamp)
  const now = new Date()
  const diffMs = now - date
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)
  
  if (diffMins < 1) return 'Just now'
  if (diffMins < 60) return `${diffMins} minute${diffMins > 1 ? 's' : ''} ago`
  if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`
  if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`
  return date.toLocaleDateString()
}

// Pending filter data for modal (from quick filter creation)
const pendingFilterData = ref(null)

onMounted(async () => {
  loadLastRunInfo()
  
  await Promise.all([
    filtersStore.fetchFilters(),
    mailbox.fetchFolders(),
    labelsStore.fetchLabels(),
    filtersStore.checkSieveStatus(),
  ])
  
  // Check if we should open the modal with pending filter data
  if (route.query.action === 'new' || filtersStore.pendingFilter) {
    if (filtersStore.pendingFilter) {
      pendingFilterData.value = filtersStore.pendingFilter
      filtersStore.pendingFilter = null
    }
    showFilterModal.value = true
  }
})

async function syncSieve() {
  const result = await filtersStore.syncToSieve()
  if (result) {
    if (result.synced) {
      toast.success('Filters synced to server - they will run automatically on new emails')
    } else if (result.oauth_account) {
      // OAuth account - shouldn't happen as button is hidden, but handle gracefully
      toast.info('Server-side filtering is not available for OAuth accounts')
    }
  } else {
    toast.error('Failed to sync filters to server')
  }
}

// Open filter modal for new filter
function openNewFilter() {
  editingFilter.value = null
  pendingFilterData.value = null
  showFilterModal.value = true
}

// Open filter modal for editing existing filter
function openEditFilter(filter) {
    editingFilter.value = filter
  pendingFilterData.value = null
  showFilterModal.value = true
}

// Handle modal close
function handleFilterModalClose() {
  showFilterModal.value = false
  editingFilter.value = null
  pendingFilterData.value = null
}

// Handle filter saved
function handleFilterSaved() {
  showFilterModal.value = false
  editingFilter.value = null
  pendingFilterData.value = null
}

// Check if a folder exists in the mailbox
function folderExists(folderName) {
  if (!folderName) return false
  return mailbox.folders.some(f => f.name === folderName)
}

// Check if filter has broken/invalid actions (missing folder/label OR folder doesn't exist)
function hasInvalidActions(filter) {
  if (!filter?.actions || filter.actions.length === 0) return false
  
  return filter.actions.some(action => {
    const actionType = action.action || action.type
    // Move to folder requires a folder value AND folder must exist
    if (actionType === 'move' || actionType === 'fileinto') {
      if (!action.value) return true
      if (!folderExists(action.value)) return true
    }
    // Add label requires a label value
    if ((actionType === 'label' || actionType === 'addlabel') && !action.value) return true
    return false
  })
}

// Get warning message for invalid actions
function getInvalidActionMessage(filter) {
  if (!filter?.actions) return ''
  
  for (const action of filter.actions) {
    const actionType = action.action || action.type
    if (actionType === 'move' || actionType === 'fileinto') {
      if (!action.value) return 'No folder selected!'
      if (!folderExists(action.value)) return `Folder "${action.value}" not found!`
    }
    if ((actionType === 'label' || actionType === 'addlabel') && !action.value) {
      return 'No label selected!'
    }
  }
  return ''
}


function confirmDelete(filter) {
  filterToDelete.value = filter
  showDeleteConfirm.value = true
}

function duplicateFilter(filter) {
  // Deep clone the filter
  const clonedFilter = JSON.parse(JSON.stringify(filter))
  
  // Remove ID so it creates a new filter and add "(Copy)" to name
  delete clonedFilter.id
  clonedFilter.name = `${filter.name} (Copy)`
  
  // Open modal with cloned data as pending filter (editingFilter stays null)
  editingFilter.value = null
  pendingFilterData.value = clonedFilter
  showFilterModal.value = true
  toast.info(`Duplicating "${filter.name}"`)
}

async function deleteFilter() {
  if (filterToDelete.value) {
    const success = await filtersStore.deleteFilter(filterToDelete.value.id)
    if (success) {
      toast.success('Filter deleted')
    } else {
      toast.error('Failed to delete filter')
    }
  }
  showDeleteConfirm.value = false
  filterToDelete.value = null
}

async function toggleFilter(filter) {
  await filtersStore.toggleFilter(filter.id, !filter.enabled)
}

// Select/Deselect all filters
async function selectAllFilters() {
  for (const filter of filtersStore.filters) {
    if (!filter.enabled) {
      await filtersStore.toggleFilter(filter.id, true)
    }
  }
  toast.success('All filters enabled')
}

async function deselectAllFilters() {
  for (const filter of filtersStore.filters) {
    if (filter.enabled) {
      await filtersStore.toggleFilter(filter.id, false)
    }
  }
  toast.success('All filters disabled')
}

// Check if all/none are selected
const allSelected = computed(() => filtersStore.filters.every(f => f.enabled))
const noneSelected = computed(() => filtersStore.filters.every(f => !f.enabled))

// Filtered filters list based on search
const filteredFilters = computed(() => {
  if (!searchQuery.value.trim()) {
    return filtersStore.filters
  }
  const query = searchQuery.value.toLowerCase().trim()
  return filtersStore.filters.filter(filter => {
    // Search in name
    if (filter.name?.toLowerCase().includes(query)) return true
    // Search in conditions (support both old flat format and new groups format)
    const groups = filter.conditions?.groups || [{ rules: filter.conditions?.rules || [] }]
    if (groups.some(group => 
      group.rules?.some(rule => 
        rule.value?.toLowerCase().includes(query) || 
        rule.field?.toLowerCase().includes(query)
      )
    )) return true
    // Search in actions
    if (filter.actions?.some(action => 
      action.value?.toLowerCase().includes(query) ||
      action.action?.toLowerCase().includes(query)
    )) return true
    return false
  })
})

// Hidden system folders that shouldn't be shown to users
const hiddenFolderNames = ['sieve', 'dovecot', 'cur', 'new', 'tmp']

function isHiddenFolder(folder) {
  const name = folder.name.toLowerCase()
  const lastPart = name.split('.').pop()
  return hiddenFolderNames.some(hidden => 
    name === hidden || 
    name === 'inbox.' + hidden ||
    lastPart === hidden
  )
}

// Filtered folders for dropdowns (excludes hidden system folders)
const filteredFolders = computed(() => {
  return mailbox.folders.filter(f => !isHiddenFolder(f))
})

// Open single filter run modal
function openSingleRunModal(filter) {
  singleRunFilter.value = filter
  singleRunFolder.value = mailbox.currentFolder || 'INBOX'
  singleRunAllFolders.value = false
  singleRunCurrentFolderName.value = ''
  singleRunResults.value = null
  singleRunning.value = false
  singleRunCancelled.value = false
  singleRunProgress.value = {
    totalProcessed: 0,
    totalMatched: 0,
    allActions: [],
    batchesCompleted: 0,
    folderTotal: 0,
    totalPages: 1,
    currentPage: 1
  }
  showSingleRunModal.value = true
}

// Cancel single filter run
function cancelSingleRun() {
  singleRunCancelled.value = true
}

// Run single filter on a specific folder
async function runFilterOnFolder(folder) {
  let currentPage = 1
  let hasMore = true
  const batchSize = 100
  
  while (hasMore && !singleRunCancelled.value) {
    const result = await filtersStore.applySingleFilter(
      singleRunFilter.value.id,
      folder,
      batchSize,
      currentPage
    )
    
    if (!result) break
    
    // Update progress
    const totalPages = result.total_pages || 1
    singleRunProgress.value.folderTotal = result.folder_total || singleRunProgress.value.folderTotal
    singleRunProgress.value.totalPages = totalPages
    singleRunProgress.value.currentPage = currentPage
    singleRunProgress.value.totalProcessed += result.batch_size || 0
    singleRunProgress.value.totalMatched += result.processed || 0
    singleRunProgress.value.allActions.push(...(result.actions || []))
    singleRunProgress.value.batchesCompleted++
    
    const actionsInBatch = result.actions?.length || 0
    
    if ((result.batch_size || 0) === 0) {
      hasMore = false
    } else if (actionsInBatch > 0) {
      // Actions were applied, stay on same page
      if (currentPage > totalPages) {
        hasMore = false
      }
    } else {
      currentPage++
      if (currentPage > totalPages) {
        hasMore = false
      }
    }
    
    // Safety limit per folder
    if (singleRunProgress.value.batchesCompleted >= 100) hasMore = false
    
    if (hasMore && !singleRunCancelled.value) {
      await new Promise(r => setTimeout(r, 200))
    }
  }
}

// Run single filter
async function runSingleFilter() {
  if (!singleRunFilter.value) return
  
  singleRunning.value = true
  singleRunCancelled.value = false
  singleRunResults.value = null
  singleRunProgress.value = {
    totalProcessed: 0,
    totalMatched: 0,
    allActions: [],
    batchesCompleted: 0,
    folderTotal: 0,
    totalPages: 1,
    currentPage: 1
  }
  
  if (singleRunAllFolders.value) {
    // Run on all folders - skip system folders that don't need filtering
    const skipTypes = ['drafts', 'sent', 'trash', 'spam', 'junk']
    const skipPatterns = ['Trash', 'Sent', 'Drafts', 'Spam', 'Junk', '[Gmail]/Sent', '[Gmail]/Trash', '[Gmail]/Spam', '[Gmail]/Drafts']
    const foldersToProcess = mailbox.folders.filter(f => {
      // Skip by type
      if (skipTypes.includes(f.type)) return false
      // Skip by name pattern
      const folderName = f.name.split('.').pop() // Get last part of folder name
      if (skipPatterns.some(p => f.name.includes(p) || folderName === p)) return false
      return true
    })
    for (const folder of foldersToProcess) {
      if (singleRunCancelled.value) break
      singleRunCurrentFolderName.value = folder.name
      singleRunProgress.value.folderTotal = 0 // Reset for each folder
      await runFilterOnFolder(folder.name)
    }
  } else {
    // Run on single folder
    singleRunCurrentFolderName.value = singleRunFolder.value
    await runFilterOnFolder(singleRunFolder.value)
  }
  
  singleRunning.value = false
  singleRunResults.value = {
    matched: singleRunProgress.value.totalMatched,
    scanned: singleRunProgress.value.totalProcessed,
    actions: singleRunProgress.value.allActions,
    cancelled: singleRunCancelled.value,
    batchesCompleted: singleRunProgress.value.batchesCompleted,
    allFolders: singleRunAllFolders.value
  }
  
  // Update filter history
  filtersStore.updateFilterRunHistory(singleRunFilter.value.id, {
    folder: singleRunAllFolders.value ? 'ALL' : singleRunFolder.value,
    matched: singleRunProgress.value.totalMatched,
    actionsCount: singleRunProgress.value.allActions.length,
    success: !singleRunCancelled.value
  })
  
  // Refresh from IMAP after filter actions
  if (singleRunProgress.value.allActions.length > 0) {
    await mailbox.fetchFolders(true)
    await mailbox.fetchMessages()
  }
}

// Format filter last run
function formatFilterLastRun(filterId) {
  const history = filtersStore.getFilterRunHistory(filterId)
  if (!history) return null
  return {
    ...history,
    timeAgo: formatLastRunTime(history.lastRun)
  }
}

function openApplyModal() {
  applyResults.value = null
  applyTargetFolder.value = mailbox.currentFolder || 'INBOX'
  isRunning.value = false
  isCancelled.value = false
  progressStats.value = {
    totalProcessed: 0,
    totalMatched: 0,
    allActions: [],
    batchesCompleted: 0,
    isComplete: false
  }
  showApplyModal.value = true
}

function cancelFilters() {
  isCancelled.value = true
}

async function runFilters() {
  applyResults.value = null
  isRunning.value = true
  isCancelled.value = false
  progressStats.value = {
    totalProcessed: 0,
    totalMatched: 0,
    allActions: [],
    batchesCompleted: 0,
    folderTotal: 0,
    totalPages: 1,
    currentPage: 1,
    isComplete: false
  }
  
  const batchSize = applyMessageLimit.value
  let currentPage = 1
  let totalPages = 1
  let hasMore = true
  
  while (hasMore && !isCancelled.value) {
    const result = await filtersStore.applyFilters(applyTargetFolder.value, [], batchSize, currentPage)
    
    if (!result) {
      // Error occurred - save error and break
      saveLastRunInfo({
        timestamp: Date.now(),
        folder: applyTargetFolder.value,
        folderTotal: progressStats.value.folderTotal,
        matched: progressStats.value.totalMatched,
        actionsCount: progressStats.value.allActions.length,
        cancelled: false,
        success: false,
        error: 'Failed to process batch ' + currentPage
      })
      break
    }
    
    // Update pagination info
    totalPages = result.total_pages || 1
    progressStats.value.folderTotal = result.folder_total || 0
    progressStats.value.totalPages = totalPages
    progressStats.value.currentPage = currentPage
    
    // Accumulate results
    progressStats.value.totalProcessed += result.batch_size || 0
    progressStats.value.totalMatched += result.processed || 0
    progressStats.value.allActions.push(...(result.actions || []))
    progressStats.value.batchesCompleted++
    
    const actionsInBatch = result.actions_count || result.actions?.length || 0
    
    // Determine next action
    if ((result.batch_size || 0) === 0) {
      // No messages in this page - we're done
      hasMore = false
    } else if (actionsInBatch > 0) {
      // Actions were applied (messages moved/deleted)
      // Stay on same page since folder shifted, but recalculate total pages
      // If total_pages decreased, we're making progress
      totalPages = result.total_pages || 1
      if (currentPage > totalPages) {
        hasMore = false
      }
    } else {
      // No actions in this batch - move to next page
      currentPage++
      if (currentPage > totalPages) {
        hasMore = false
      }
    }
    
    // Safety limit
    if (progressStats.value.batchesCompleted >= 100) {
      hasMore = false
    }
    
    // Small delay between batches
    if (hasMore && !isCancelled.value) {
      await new Promise(resolve => setTimeout(resolve, 200))
    }
  }
  
  progressStats.value.isComplete = !isCancelled.value
  isRunning.value = false
  
  // Set final results
  applyResults.value = {
    processed: progressStats.value.totalMatched,
    total_messages: progressStats.value.totalProcessed,
    folder_total: progressStats.value.folderTotal,
    actions: progressStats.value.allActions,
    cancelled: isCancelled.value,
    batchesCompleted: progressStats.value.batchesCompleted
  }
  
  // Save last run info
  saveLastRunInfo({
    timestamp: Date.now(),
    folder: applyTargetFolder.value,
    folderTotal: progressStats.value.folderTotal,
    matched: progressStats.value.totalMatched,
    actionsCount: progressStats.value.allActions.length,
    cancelled: isCancelled.value,
    success: !isCancelled.value,
    error: null
  })
  
  // Refresh messages if we're viewing the target folder
  if (progressStats.value.allActions.length > 0 && mailbox.currentFolder === applyTargetFolder.value) {
    await mailbox.fetchMessages()
  }
}

// Get action info helper
function getActionInfo(action) {
  return filtersStore.actions.find(a => a.id === action.action) || {}
}

// Get folder display name
function getFolderName(folderName) {
  if (folderName === 'INBOX') return 'Inbox'
  if (folderName?.startsWith('INBOX.')) {
    // Replace dots with arrows for better readability
    let displayName = folderName.slice(6).replace(/\./g, ' / ')
    // Rename common folder names
    displayName = displayName.replace('Deleted Items', 'Trash')
    return displayName
  }
  let displayName = folderName?.replace(/\./g, ' / ') || folderName
  // Rename common folder names
  if (displayName) {
    displayName = displayName.replace('Deleted Items', 'Trash')
  }
  return displayName
}
</script>

<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Email Filters</h2>
        <p class="text-sm text-surface-500">Automatically organize incoming emails based on rules</p>
      </div>
      <div class="flex gap-2">
        <button @click="openApplyModal" class="btn-secondary" :disabled="filtersStore.filters.length === 0">
          <span class="material-symbols-rounded">play_arrow</span>
          Run Filters
        </button>
        <button @click="openNewFilter()" class="btn-primary">
          <span class="material-symbols-rounded">add</span>
          New Filter
        </button>
      </div>
    </div>
    
    <!-- Server-side Filtering (Sieve) Card - Only for non-OAuth accounts -->
    <div v-if="!isOAuthAccount" class="card p-4">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div :class="[
            'w-10 h-10 rounded-xl flex items-center justify-center',
            filtersStore.sieveStatus.active ? 'bg-primary-100 dark:bg-primary-500/20' : 'bg-surface-100 dark:bg-surface-700'
          ]">
            <span :class="[
              'material-symbols-rounded',
              filtersStore.sieveStatus.active ? 'text-primary-500' : 'text-surface-400'
            ]">cloud_sync</span>
          </div>
          <div>
            <h3 class="font-medium text-surface-900 dark:text-surface-100">Server-side Filtering</h3>
            <p class="text-xs text-surface-500">
              <template v-if="!filtersStore.sieveStatus.checked">
                Checking availability...
              </template>
              <template v-else-if="!filtersStore.sieveStatus.available">
                Not available: {{ filtersStore.sieveStatus.error || 'Connection failed' }}
              </template>
              <template v-else-if="filtersStore.sieveStatus.active">
                Active - filters run automatically on new emails
              </template>
              <template v-else>
                Inactive - sync to enable automatic filtering
              </template>
            </p>
            <p v-if="filtersStore.sieveStatus.debug" class="text-[10px] text-surface-400 mt-0.5">
              Host: {{ filtersStore.sieveStatus.debug.host }}:{{ filtersStore.sieveStatus.debug.port }}
            </p>
          </div>
        </div>
        <button 
          v-if="filtersStore.sieveStatus.available"
          @click="syncSieve"
          class="btn-secondary btn-sm"
          :disabled="filtersStore.syncing || filtersStore.filters.length === 0"
        >
          <span v-if="filtersStore.syncing" class="spinner w-4 h-4"></span>
          <span v-else class="material-symbols-rounded">sync</span>
          {{ filtersStore.syncing ? 'Syncing...' : 'Sync to Server' }}
        </button>
      </div>
    </div>
    
    <!-- Last Run Status -->
    <div v-if="lastRunInfo" class="flex items-center justify-between p-3 rounded-xl bg-surface-100 dark:bg-surface-800 border border-surface-200 dark:border-surface-700">
      <div class="flex items-center gap-3">
        <span 
          :class="[
            'material-symbols-rounded text-xl',
            lastRunInfo.success ? 'text-green-500' : (lastRunInfo.cancelled ? 'text-amber-500' : 'text-red-500')
          ]"
        >
          {{ lastRunInfo.success ? 'check_circle' : (lastRunInfo.cancelled ? 'cancel' : 'error') }}
        </span>
        <div>
          <p class="text-sm font-medium text-surface-800 dark:text-surface-200">
            Last run: {{ formatLastRunTime(lastRunInfo.timestamp) }}
          </p>
          <p class="text-xs text-surface-500">
            <template v-if="lastRunInfo.success">
              {{ lastRunInfo.matched }} matched of {{ lastRunInfo.folderTotal }} in {{ getFolderName(lastRunInfo.folder) }}
            </template>
            <template v-else-if="lastRunInfo.cancelled">
              Cancelled after {{ lastRunInfo.matched }} matches
            </template>
            <template v-else>
              Error: {{ lastRunInfo.error || 'Unknown error' }}
            </template>
          </p>
        </div>
      </div>
      <button @click="openApplyModal" class="text-xs text-primary-500 hover:text-primary-600 font-medium">
        Run again
      </button>
    </div>
    
    <!-- Loading -->
    <div v-if="filtersStore.loading" class="flex justify-center py-8">
      <span class="spinner text-primary-500"></span>
    </div>
    
    <!-- Empty state -->
    <div v-else-if="filtersStore.filters.length === 0" class="text-center py-12 bg-surface-100 dark:bg-surface-800 rounded-xl">
      <span class="material-symbols-rounded text-5xl text-surface-400 mb-3">filter_list</span>
      <p class="text-surface-600 dark:text-surface-400 mb-4">No filters yet</p>
      <button @click="openNewFilter()" class="btn-primary">
        <span class="material-symbols-rounded">add</span>
        Create Your First Filter
      </button>
    </div>
    
    <!-- Filters list -->
    <div v-else class="space-y-3">
      <!-- Search and Bulk Actions -->
      <div class="flex items-center gap-4 p-3 bg-surface-100 dark:bg-surface-800 rounded-xl">
        <!-- Search input -->
        <div class="flex-1 relative">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
          <input 
            v-model="searchQuery"
            type="text"
            placeholder="Search filters..."
            class="input pl-10 w-full text-sm"
          />
          <button 
            v-if="searchQuery"
            @click="searchQuery = ''"
            class="absolute right-2 top-1/2 -translate-y-1/2 btn-ghost btn-icon btn-xs"
          >
            <span class="material-symbols-rounded text-sm">close</span>
          </button>
        </div>
        
        <!-- Filter count and bulk actions -->
        <div class="flex items-center gap-3 shrink-0">
          <span class="text-sm text-surface-600 dark:text-surface-400 whitespace-nowrap">
            {{ filtersStore.filters.filter(f => f.enabled).length }} of {{ filtersStore.filters.length }} filters enabled
          </span>
          <div class="flex items-center gap-1">
            <button 
              @click="selectAllFilters" 
              class="btn-ghost btn-sm"
              :disabled="allSelected"
              title="Enable All"
            >
              <span class="material-symbols-rounded text-lg">check_box</span>
              Enable All
            </button>
            <button 
              @click="deselectAllFilters" 
              class="btn-ghost btn-sm"
              :disabled="noneSelected"
              title="Disable All"
            >
              <span class="material-symbols-rounded text-lg">check_box_outline_blank</span>
              Disable All
            </button>
          </div>
        </div>
      </div>
      
      <!-- No results message -->
      <div v-if="searchQuery && filteredFilters.length === 0" class="text-center py-8 bg-surface-50 dark:bg-surface-800/50 rounded-xl">
        <span class="material-symbols-rounded text-4xl text-surface-400 mb-2">search_off</span>
        <p class="text-surface-500">No filters match "{{ searchQuery }}"</p>
        <button @click="searchQuery = ''" class="btn-ghost btn-sm mt-2 text-primary-500">
          Clear search
        </button>
      </div>
      
      <div
        v-for="filter in filteredFilters"
        :key="filter.id"
        class="card p-4"
      >
        <div class="flex items-center gap-4">
          <!-- Enable/Disable toggle -->
          <button
            @click="toggleFilter(filter)"
            :class="['w-10 h-6 rounded-full transition-colors relative shrink-0', filter.enabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
            :title="filter.enabled ? 'Disable filter' : 'Enable filter'"
          >
            <span 
              :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', filter.enabled ? 'translate-x-4' : 'translate-x-0']"
            ></span>
          </button>
          
          <!-- Filter info -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
            <h3 class="font-medium text-surface-900 dark:text-surface-100">{{ filter.name }}</h3>
              <span v-if="hasInvalidActions(filter)" class="text-red-500 flex items-center gap-1" title="Filter has invalid actions">
                <span class="material-symbols-rounded text-base">error</span>
              </span>
            </div>
            <div class="text-xs text-surface-500 mt-1">
              <span>{{ filter.conditions?.rules?.length || 0 }} condition(s)</span>
              <span class="mx-2">•</span>
              <span>{{ filter.actions?.length || 0 }} action(s)</span>
              <span v-if="filter.stop_processing" class="mx-2">•</span>
              <span v-if="filter.stop_processing" class="text-amber-500">Stops processing</span>
            </div>
            <!-- Show action targets -->
            <div class="mt-1 text-xs text-surface-600 dark:text-surface-300 font-mono">
              {{ filter.actions?.map(a => `${a.action}:${a.value || 'EMPTY'}`).join(', ') }}
            </div>
            <!-- Invalid action warning -->
            <div v-if="hasInvalidActions(filter)" class="mt-1 text-xs text-red-500 flex items-center gap-1">
              <span class="material-symbols-rounded text-sm">warning</span>
              <span>{{ getInvalidActionMessage(filter) }}</span>
            </div>
          </div>
          
          <!-- Actions -->
          <div class="flex items-center gap-1">
            <button @click="openSingleRunModal(filter)" class="btn-ghost btn-icon btn-sm text-primary-500" title="Run Now">
              <span class="material-symbols-rounded">play_arrow</span>
            </button>
            <button @click="duplicateFilter(filter)" class="btn-ghost btn-icon btn-sm text-surface-500" title="Duplicate">
              <span class="material-symbols-rounded">content_copy</span>
            </button>
            <button @click="openEditFilter(filter)" class="btn-ghost btn-icon btn-sm" title="Edit">
              <span class="material-symbols-rounded">edit</span>
            </button>
            <button @click="confirmDelete(filter)" class="btn-ghost btn-icon btn-sm text-red-500" title="Delete">
              <span class="material-symbols-rounded">delete</span>
            </button>
          </div>
        </div>
        
        <!-- Per-filter last run info -->
        <div v-if="formatFilterLastRun(filter.id)" class="mt-3 pt-3 border-t border-surface-200 dark:border-surface-700">
          <div class="flex items-center justify-between text-xs">
            <div class="flex items-center gap-2 text-surface-500">
              <span class="material-symbols-rounded text-sm text-green-500">check_circle</span>
              <span>Last run: {{ formatFilterLastRun(filter.id).timeAgo }}</span>
              <span class="mx-1">•</span>
              <span>{{ formatFilterLastRun(filter.id).matched }} matched in {{ getFolderName(formatFilterLastRun(filter.id).folder) }}</span>
            </div>
            <button 
              @click="openSingleRunModal(filter)" 
              class="text-primary-500 hover:text-primary-600 font-medium"
            >
              Run again
            </button>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Filter Editor Modal (shared component) -->
    <FilterModal
      :show="showFilterModal"
      :editing-filter="editingFilter"
      :initial-data="pendingFilterData"
      :hide-list-tab="true"
      @close="handleFilterModalClose"
      @saved="handleFilterSaved"
    />
    
    <!-- Delete Confirmation -->
    <ConfirmModal
      :show="showDeleteConfirm"
      title="Delete Filter"
      :message="`Are you sure you want to delete '${filterToDelete?.name}'?`"
      confirm-text="Delete"
      type="danger"
      @confirm="deleteFilter"
      @cancel="showDeleteConfirm = false; filterToDelete = null"
    />
    
    <!-- Apply Filters Modal -->
    <Teleport to="body">
      <div v-if="showApplyModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @mousedown.self="!isRunning && (showApplyModal = false)">
        <div class="w-full max-w-lg bg-white dark:bg-surface-800 rounded-2xl shadow-2xl overflow-hidden">
          <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
              Run Filters
            </h3>
            <button @click="showApplyModal = false" class="btn-ghost btn-icon" :disabled="isRunning">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="p-6 space-y-4">
            <!-- Settings (show before running) -->
            <div v-if="!applyResults && !isRunning" class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  Apply filters to folder
                </label>
                <select v-model="applyTargetFolder" class="select-input w-full">
                  <option v-for="folder in filteredFolders" :key="folder.name" :value="folder.name">
                    {{ getFolderName(folder.name) }}
                  </option>
                </select>
              </div>
              
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  Batch size (messages per request)
                </label>
                <select v-model="applyMessageLimit" class="select-input w-full">
                  <option :value="50">50 messages</option>
                  <option :value="100">100 messages</option>
                  <option :value="250">250 messages</option>
                  <option :value="500">500 messages</option>
                </select>
              </div>
              
              <div class="bg-surface-100 dark:bg-surface-700 rounded-lg p-3">
                <p class="text-sm text-surface-600 dark:text-surface-400">
                  <span class="font-medium">{{ filtersStore.filters.filter(f => f.enabled).length }}</span> enabled filter(s) will be applied to
                  <span class="font-medium">all messages</span> in
                  <span class="font-medium">{{ getFolderName(applyTargetFolder) }}</span>
                </p>
              </div>
            </div>
            
            <!-- Running state with progress -->
            <div v-if="isRunning" class="flex flex-col items-center py-6">
              <span class="spinner text-primary-500 w-10 h-10 mb-4"></span>
              <p class="text-surface-900 dark:text-surface-100 font-medium mb-2">
                Processing {{ getFolderName(applyTargetFolder) }}...
              </p>
              <div class="text-sm text-surface-500 text-center space-y-1">
                <p>Page {{ progressStats.currentPage }} of {{ progressStats.totalPages }}</p>
                <p>{{ Math.min(progressStats.currentPage * applyMessageLimit, progressStats.folderTotal) }} of {{ progressStats.folderTotal }} messages scanned</p>
                <p class="text-primary-500 font-medium">{{ progressStats.totalMatched }} matched so far</p>
              </div>
              <!-- Progress bar -->
              <div class="w-full max-w-xs mt-4 bg-surface-200 dark:bg-surface-700 rounded-full h-2">
                <div 
                  class="bg-primary-500 h-2 rounded-full transition-all duration-300"
                  :style="{ width: `${Math.min(100, (progressStats.currentPage / Math.max(1, progressStats.totalPages)) * 100)}%` }"
                ></div>
              </div>
            </div>
            
            <!-- Results -->
            <div v-else-if="applyResults">
              <div class="text-center mb-4">
                <span :class="['material-symbols-rounded text-4xl mb-2', applyResults.cancelled ? 'text-amber-500' : (applyResults.actions?.length > 0 ? 'text-primary-500' : 'text-surface-400')]">
                  {{ applyResults.cancelled ? 'cancel' : (applyResults.actions?.length > 0 ? 'check_circle' : 'info') }}
                </span>
                <p class="text-lg font-medium text-surface-900 dark:text-surface-100">
                  {{ applyResults.cancelled ? 'Cancelled' : 'Complete' }} — {{ applyResults.processed }} messages matched
                </p>
                <p class="text-sm text-surface-500 mt-1">
                  Scanned {{ applyResults.folder_total || applyResults.total_messages }} messages in {{ getFolderName(applyTargetFolder) }}
                  <span v-if="applyResults.batchesCompleted > 1">({{ applyResults.batchesCompleted }} batches)</span>
                </p>
              </div>
              
              <div v-if="applyResults.total_messages === 0" class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-3 text-sm text-amber-700 dark:text-amber-400">
                <span class="material-symbols-rounded text-base align-text-bottom mr-1">warning</span>
                No messages found in this folder. Try selecting a different folder.
              </div>
              
              <div v-else-if="applyResults.actions?.length > 0" class="max-h-48 overflow-y-auto bg-surface-100 dark:bg-surface-700 rounded-lg p-3 text-sm">
                <div v-for="(action, i) in applyResults.actions" :key="i" class="py-1 flex items-center gap-2">
                  <span class="material-symbols-rounded text-primary-500 text-base">check</span>
                  <span class="text-surface-500">{{ action.filter }}:</span>
                  <span class="text-surface-700 dark:text-surface-300">{{ action.action }}</span>
                  <span class="text-surface-500 truncate">"{{ action.subject?.substring(0, 25) }}{{ action.subject?.length > 25 ? '...' : '' }}"</span>
                </div>
              </div>
              
              <p v-else class="text-center text-surface-500 py-4">
                No messages matched your filter conditions
              </p>
            </div>
          </div>
          
          <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-2">
            <!-- Cancel button while running -->
            <button v-if="isRunning" @click="cancelFilters" class="btn-secondary text-red-500 border-red-300 hover:bg-red-50 dark:hover:bg-red-900/20">
              <span class="material-symbols-rounded">stop</span>
              Cancel
            </button>
            
            <!-- Run again after results -->
            <button v-if="applyResults && !isRunning" @click="applyResults = null" class="btn-secondary">
              <span class="material-symbols-rounded">refresh</span>
              New Run
            </button>
            
            <!-- Start button -->
            <button v-if="!applyResults && !isRunning" @click="runFilters" class="btn-primary">
              <span class="material-symbols-rounded">play_arrow</span>
              Start
            </button>
            
            <!-- Close button after completion -->
            <button v-if="applyResults && !isRunning" @click="showApplyModal = false" class="btn-primary">
              Close
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Single Filter Run Modal -->
    <Teleport to="body">
      <div v-if="showSingleRunModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @mousedown.self="!singleRunning && (showSingleRunModal = false)">
        <div class="w-full max-w-lg bg-white dark:bg-surface-800 rounded-2xl shadow-2xl overflow-hidden">
          <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
              Run Filter: {{ singleRunFilter?.name }}
            </h3>
            <button @click="showSingleRunModal = false" class="btn-ghost btn-icon" :disabled="singleRunning">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="p-6 space-y-4">
            <!-- Settings (before running) -->
            <div v-if="!singleRunResults && !singleRunning" class="space-y-4">
              <!-- Folder scope toggle -->
              <div class="flex rounded-xl overflow-hidden border border-surface-200 dark:border-surface-700">
                <button
                  @click="singleRunAllFolders = false"
                  :class="[
                    'flex-1 flex items-center justify-center gap-2 py-2.5 text-sm font-medium transition-colors',
                    !singleRunAllFolders 
                      ? 'bg-primary-500 text-white' 
                      : 'bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">folder</span>
                  Single Folder
                </button>
                <button
                  @click="singleRunAllFolders = true"
                  :class="[
                    'flex-1 flex items-center justify-center gap-2 py-2.5 text-sm font-medium transition-colors',
                    singleRunAllFolders 
                      ? 'bg-primary-500 text-white' 
                      : 'bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">folder_copy</span>
                  All Folders
                </button>
              </div>
              
              <!-- Folder selector (only for single folder mode) -->
              <div v-if="!singleRunAllFolders">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  Select folder
                </label>
                <select v-model="singleRunFolder" class="select-input w-full">
                  <option v-for="folder in filteredFolders" :key="folder.name" :value="folder.name">
                    {{ getFolderName(folder.name) }}
                  </option>
                </select>
              </div>
              
              <div class="bg-surface-100 dark:bg-surface-700 rounded-lg p-3">
                <p class="text-sm text-surface-600 dark:text-surface-400">
                  This will apply "<span class="font-medium">{{ singleRunFilter?.name }}</span>" to all messages in 
                  <span class="font-medium">{{ singleRunAllFolders ? 'all folders' : getFolderName(singleRunFolder) }}</span>
                </p>
              </div>
            </div>
            
            <!-- Running with progress -->
            <div v-if="singleRunning" class="flex flex-col items-center py-6">
              <span class="spinner text-primary-500 w-10 h-10 mb-4"></span>
              <p class="text-surface-900 dark:text-surface-100 font-medium mb-2">
                Processing {{ getFolderName(singleRunCurrentFolderName) }}...
              </p>
              <div class="text-sm text-surface-500 text-center space-y-1">
                <p v-if="singleRunAllFolders" class="text-xs text-primary-400 mb-1">Running on all folders</p>
                <p>Page {{ singleRunProgress.currentPage }} of {{ singleRunProgress.totalPages }}</p>
                <p>{{ singleRunProgress.totalProcessed }} messages scanned total</p>
                <p class="text-primary-500 font-medium">{{ singleRunProgress.totalMatched }} matched so far</p>
                <p v-if="singleRunProgress.allActions.length > 0" class="text-green-500">
                  {{ singleRunProgress.allActions.length }} action(s) applied
                </p>
              </div>
              <!-- Progress bar -->
              <div class="w-full max-w-xs mt-4 bg-surface-200 dark:bg-surface-700 rounded-full h-2">
                <div 
                  class="bg-primary-500 h-2 rounded-full transition-all duration-300"
                  :style="{ width: `${Math.min(100, (singleRunProgress.currentPage / Math.max(1, singleRunProgress.totalPages)) * 100)}%` }"
                ></div>
              </div>
              <p class="text-xs text-surface-400 mt-2">
                Batch {{ singleRunProgress.batchesCompleted }} completed
              </p>
            </div>
            
            <!-- Results -->
            <div v-if="singleRunResults && !singleRunning" class="space-y-3">
              <div class="text-center mb-4">
                <span :class="['material-symbols-rounded text-4xl mb-2', singleRunResults.cancelled ? 'text-amber-500' : (singleRunResults.actions.length > 0 ? 'text-primary-500' : 'text-surface-400')]">
                  {{ singleRunResults.cancelled ? 'cancel' : (singleRunResults.actions.length > 0 ? 'check_circle' : 'info') }}
                </span>
                <p class="text-lg font-medium text-surface-900 dark:text-surface-100">
                  {{ singleRunResults.cancelled ? 'Cancelled' : 'Complete' }} — {{ singleRunResults.matched }} messages matched
                </p>
                <p class="text-sm text-surface-500 mt-1">
                  Scanned {{ singleRunResults.scanned }} messages in {{ singleRunResults.allFolders ? 'all folders' : getFolderName(singleRunFolder) }}
                  <span v-if="singleRunResults.batchesCompleted > 1">({{ singleRunResults.batchesCompleted }} batches)</span>
                </p>
              </div>
              
              <div v-if="singleRunResults.scanned === 0" class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-3 text-sm text-amber-700 dark:text-amber-400">
                <span class="material-symbols-rounded text-base align-text-bottom mr-1">warning</span>
                No messages found. {{ singleRunResults.allFolders ? 'All folders are empty.' : 'Try selecting a different folder or run on all folders.' }}
              </div>
              
              <div v-else-if="singleRunResults.actions.length > 0" class="max-h-48 overflow-y-auto bg-surface-100 dark:bg-surface-700 rounded-lg p-3 text-sm">
                <div v-for="(action, i) in singleRunResults.actions.slice(0, 15)" :key="i" class="py-1 flex items-center gap-2">
                  <span class="material-symbols-rounded text-primary-500 text-base">check</span>
                  <span class="text-surface-700 dark:text-surface-300">{{ action.action }}</span>
                  <span class="text-surface-500 truncate">"{{ action.subject?.substring(0, 30) }}{{ action.subject?.length > 30 ? '...' : '' }}"</span>
                </div>
                <p v-if="singleRunResults.actions.length > 15" class="text-xs text-surface-500 mt-2">
                  + {{ singleRunResults.actions.length - 15 }} more actions
                </p>
              </div>
              
              <p v-else class="text-center text-surface-500 py-4">
                No messages matched this filter's conditions
              </p>
            </div>
          </div>
          
          <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-2">
            <!-- Cancel button while running -->
            <button v-if="singleRunning" @click="cancelSingleRun" class="btn-secondary text-red-500 border-red-300 hover:bg-red-50 dark:hover:bg-red-900/20">
              <span class="material-symbols-rounded">stop</span>
              Cancel
            </button>
            
            <button v-if="!singleRunResults && !singleRunning" @click="showSingleRunModal = false" class="btn-ghost">
              Cancel
            </button>
            <button v-if="!singleRunResults && !singleRunning" @click="runSingleFilter" class="btn-primary">
              <span class="material-symbols-rounded">play_arrow</span>
              Run Filter
            </button>
            
            <!-- Run again after results -->
            <button v-if="singleRunResults && !singleRunning" @click="singleRunResults = null; singleRunProgress = { totalProcessed: 0, totalMatched: 0, allActions: [], batchesCompleted: 0, folderTotal: 0, totalPages: 1, currentPage: 1 }" class="btn-secondary">
              <span class="material-symbols-rounded">refresh</span>
              New Run
            </button>
            <button v-if="singleRunResults && !singleRunning" @click="showSingleRunModal = false" class="btn-primary">
              Done
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Create Folder Modal -->
    <Teleport to="body">
      <div 
        v-if="showCreateFolder" 
        class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50" 
        @mousedown.self="showCreateFolder = false"
      >
        <div class="w-full max-w-sm bg-white dark:bg-surface-800 rounded-xl shadow-2xl p-6">
          <h4 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4">Create New Folder</h4>
          
          <div class="space-y-4">
            <!-- Parent folder selection (optional for subfolders) -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Parent folder (optional)</label>
              <select v-model="newFolderParent" class="select-input w-full text-sm">
                <option value="">None (create in Inbox)</option>
                <option value="INBOX">Inbox</option>
                <option v-for="folder in parentFolderOptions" :key="folder.name" :value="folder.name">
                  {{ getFolderName(folder.name) }}
                </option>
              </select>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Folder name</label>
              <input
                v-model="newFolderName"
                type="text"
                class="input w-full"
                placeholder="Enter folder name..."
                @keyup.enter="createNewFolder"
              />
            </div>
            
            <!-- Preview -->
            <div v-if="newFolderName.trim()">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Will create:</label>
              <span class="text-sm text-surface-600 dark:text-surface-400">
                {{ newFolderParent ? getFolderName(newFolderParent) + ' / ' : 'Inbox / ' }}{{ newFolderName }}
              </span>
            </div>
          </div>
          
          <div class="flex justify-end gap-2 mt-6">
            <button @click="showCreateFolder = false; newFolderName = ''; newFolderParent = ''" class="btn-ghost">Cancel</button>
            <button 
              @click="createNewFolder" 
              :disabled="creatingFolder || !newFolderName.trim()"
              class="btn-primary"
            >
              <span v-if="creatingFolder" class="material-symbols-rounded animate-spin text-sm">progress_activity</span>
              <span v-else>Create Folder</span>
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    
    <!-- Create Label Modal -->
    <Teleport to="body">
      <div 
        v-if="showCreateLabel" 
        class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50" 
        @mousedown.self="showCreateLabel = false"
      >
        <div class="w-full max-w-sm bg-white dark:bg-surface-800 rounded-xl shadow-2xl p-6">
          <h4 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4">Create New Label</h4>
          
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Label Name</label>
              <input
                v-model="newLabelName"
                type="text"
                class="input w-full"
                placeholder="Enter label name..."
                @keyup.enter="createNewLabel"
              />
            </div>
            
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Color</label>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="color in labelColorPresets"
                  :key="color"
                  @click="newLabelColor = color"
                  class="w-6 h-6 rounded-full border-2 transition-all"
                  :class="newLabelColor === color ? 'border-surface-900 dark:border-white scale-110' : 'border-transparent hover:scale-105'"
                  :style="{ backgroundColor: color }"
                ></button>
              </div>
            </div>
            
            <!-- Preview -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">Preview</label>
              <span 
                class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium text-white"
                :style="{ backgroundColor: newLabelColor }"
              >
                {{ newLabelName || 'Label Name' }}
              </span>
            </div>
          </div>
          
          <div class="flex justify-end gap-2 mt-6">
            <button @click="showCreateLabel = false; newLabelName = ''" class="btn-ghost">Cancel</button>
            <button 
              @click="createNewLabel" 
              :disabled="creatingLabel || !newLabelName.trim()"
              class="btn-primary"
            >
              <span v-if="creatingLabel" class="material-symbols-rounded animate-spin text-sm">progress_activity</span>
              <span v-else>Create Label</span>
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.select-input {
  @apply px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 outline-none;
}

/* Dark mode option styling */
.dark .select-input,
:deep(.dark) .select-input {
  color-scheme: dark;
}

.select-input option {
  @apply bg-white text-surface-900;
}

.dark .select-input option,
:deep(.dark) .select-input option {
  @apply bg-surface-800 text-surface-100;
}
</style>
