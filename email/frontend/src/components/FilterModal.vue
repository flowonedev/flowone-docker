<script setup>
import { ref, watch, onMounted, onUnmounted, computed } from 'vue'
import { useFiltersStore } from '@/stores/filters'
import { useMailboxStore } from '@/stores/mailbox'
import { useLabelsStore } from '@/stores/labels'
import { useAccountsStore } from '@/stores/accounts'
import { useToastStore } from '@/stores/toast'
import { isDebugEnabled } from '@/utils/debug'

const props = defineProps({
  show: Boolean,
  initialData: Object,
  editingFilter: Object,
  hideListTab: Boolean,
})

const isMobile = ref(window.innerWidth < 768)
function checkMobile() { isMobile.value = window.innerWidth < 768 }

const emit = defineEmits(['close', 'saved'])

const filtersStore = useFiltersStore()
const mailbox = useMailboxStore()
const labelsStore = useLabelsStore()
const accountsStore = useAccountsStore()
const toast = useToastStore()

const saving = ref(false)
const showRunNow = ref(false)

// Tab state: 'editor' or 'list'
const activeTab = ref('editor')

// Filter list state
const searchQuery = ref('')
const filterToDelete = ref(null)
const showDeleteConfirm = ref(false)

// Draggable modal state
const isDragging = ref(false)
const dragOffset = ref({ x: 0, y: 0 })
const modalPosition = ref({ x: null, y: null })
const isHoveringModal = ref(false)
const runNowFolder = ref('INBOX')
const isRunning = ref(false)
const isCancelled = ref(false)
const runResults = ref(null)
const createdFilterId = ref(null)
const runProgress = ref({
  totalProcessed: 0,
  totalMatched: 0,
  allActions: [],
  batchesCompleted: 0,
  folderTotal: 0,
  totalPages: 1,
  currentPage: 1
})

// Help section state
const showFilterHelp = ref(false)

// Run single filter modal state
const showRunFilterModal = ref(false)
const runFilterTarget = ref(null)
const runFilterFolder = ref('INBOX')
const runFilterAllFolders = ref(false)
const runFilterRunning = ref(false)
const runFilterCancelled = ref(false)
const runFilterResults = ref(null)
const runFilterProgress = ref({
  totalProcessed: 0,
  totalMatched: 0,
  allActions: [],
  batchesCompleted: 0,
  folderTotal: 0,
  totalPages: 1,
  currentPage: 1
})

// Create new folder/label state
const showCreateFolder = ref(false)
const newFolderName = ref('')
const newFolderParent = ref('') // Parent folder for subfolders
const creatingFolder = ref(false)
const showCreateLabel = ref(false)
const newLabelName = ref('')
const newLabelColor = ref('#3b82f6')
const creatingLabel = ref(false)
const createTargetAction = ref(null) // Which action index we're creating for

// Form state - now with condition groups for AND/OR support
const form = ref({
  name: '',
  enabled: true,
  priority: 0,
  stop_processing: false,
  conditions: {
    match: 'all', // How to combine groups: 'all' (AND) or 'any' (OR)
    groups: [
      {
        match: 'all', // How to combine rules within group
        rules: [{ field: 'from', operator: 'contains', value: '' }]
      }
    ],
    exceptions: {
      match: 'any', // Exclude if ANY exception matches
      rules: []
    }
  },
  actions: [{ action: 'move', value: '' }]
})

// Extended fields including has_label
const extendedFields = computed(() => {
  const fields = [...filtersStore.fields]
  // Add has_label if not already present
  if (!fields.find(f => f.id === 'has_label')) {
    fields.push({ id: 'has_label', name: 'Has Label', description: 'Message has specific label' })
  }
  return fields
})

// Filtered filters list based on search
const filteredFilters = computed(() => {
  if (!searchQuery.value.trim()) {
    return filtersStore.filters
  }
  const query = searchQuery.value.toLowerCase().trim()
  return filtersStore.filters.filter(filter => {
    // Search in name
    if (filter.name?.toLowerCase().includes(query)) return true
    // Search in conditions
    const conditionsStr = JSON.stringify(filter.conditions || {}).toLowerCase()
    if (conditionsStr.includes(query)) return true
    // Search in actions
    const actionsStr = JSON.stringify(filter.actions || []).toLowerCase()
    if (actionsStr.includes(query)) return true
    return false
  })
})

// Get condition count (handles both old and new format)
function getConditionCount(filter) {
  if (filter.conditions?.groups) {
    return filter.conditions.groups.reduce((sum, g) => sum + (g.rules?.length || 0), 0)
  }
  return filter.conditions?.rules?.length || 0
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
    const actionType = action.action || action.type // Support both formats
    // Move to folder requires a folder value AND folder must exist
    if (actionType === 'move' || actionType === 'fileinto') {
      if (!action.value) return true
      if (!folderExists(action.value)) return true
    }
    // Add label requires a label value
    if ((actionType === 'label' || actionType === 'addlabel') && !action.value) return true
    // Forward requires an email
    if (actionType === 'redirect' && !action.value) return true
    return false
  })
}

// Get list of invalid action issues
function getInvalidActionMessages(filter) {
  const issues = []
  if (!filter?.actions) return issues
  
  filter.actions.forEach((action, i) => {
    const actionType = action.action || action.type // Support both formats
    if (actionType === 'move' || actionType === 'fileinto') {
      if (!action.value) {
        issues.push('No folder selected')
      } else if (!folderExists(action.value)) {
        issues.push(`Folder "${action.value}" not found!`)
      }
    }
    if ((actionType === 'label' || actionType === 'addlabel') && !action.value) {
      issues.push('No label selected')
    }
    if (actionType === 'redirect' && !action.value) {
      issues.push('No email address')
    }
  })
  return issues
}

// Check current form for invalid actions
const formHasInvalidActions = computed(() => {
  return form.value.actions.some(action => {
    const actionType = action.action || action.type // Support both formats
    if ((actionType === 'move' || actionType === 'fileinto') && !action.value) return true
    if ((actionType === 'label' || actionType === 'addlabel') && !action.value) return true
    if (actionType === 'redirect' && !action.value) return true
    return false
  })
})

// Edit filter from list
function editFilterFromList(filter) {
  populateForm(filter, true)
  // Store reference for updates
  internalEditingFilter.value = filter
  activeTab.value = 'editor'
}

// Toggle filter enabled state
async function toggleFilter(filter) {
  const newEnabled = !filter.enabled
  const result = await filtersStore.updateFilter(filter.id, { enabled: newEnabled })
  if (result) {
    toast.success(newEnabled ? 'Filter enabled' : 'Filter disabled')
  } else {
    toast.error('Failed to update filter')
  }
}

// Duplicate filter
function duplicateFilterFromList(filter) {
  const cloned = JSON.parse(JSON.stringify(filter))
  delete cloned.id
  cloned.name = `${filter.name} (Copy)`
  populateForm(cloned, false)
  internalEditingFilter.value = null
  activeTab.value = 'editor'
  toast.info(`Duplicating "${filter.name}"`)
}

// Confirm delete
function confirmDelete(filter) {
  filterToDelete.value = filter
  showDeleteConfirm.value = true
}

// Delete filter
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

// Open run filter modal
function openRunFilterModal(filter) {
  runFilterTarget.value = filter
  runFilterFolder.value = mailbox.currentFolder || 'INBOX'
  runFilterAllFolders.value = false
  runFilterResults.value = null
  runFilterRunning.value = false
  runFilterCancelled.value = false
  runFilterProgress.value = {
    totalProcessed: 0,
    totalMatched: 0,
    allActions: [],
    batchesCompleted: 0,
    folderTotal: 0,
    totalPages: 1,
    currentPage: 1
  }
  showRunFilterModal.value = true
}

// Run the filter on selected folder(s)
async function executeRunFilter() {
  if (!runFilterTarget.value) return
  
  runFilterRunning.value = true
  runFilterCancelled.value = false
  runFilterResults.value = null
  runFilterProgress.value = {
    totalProcessed: 0,
    totalMatched: 0,
    allActions: [],
    batchesCompleted: 0,
    folderTotal: 0,
    totalPages: 1,
    currentPage: 1
  }
  
  const foldersToProcess = runFilterAllFolders.value 
    ? filteredFolders.value.filter(f => !['trash', 'spam', 'junk', 'drafts'].includes(f.type))
    : [{ name: runFilterFolder.value }]
  
  for (const folder of foldersToProcess) {
    if (runFilterCancelled.value) break
    
    let currentPage = 1
    let hasMore = true
    const batchSize = 100
    
    while (hasMore && !runFilterCancelled.value) {
      const result = await filtersStore.applySingleFilter(
        runFilterTarget.value.id,
        folder.name,
        batchSize,
        currentPage
      )
      
      if (!result) break
      
      const totalPages = result.total_pages || 1
      runFilterProgress.value.folderTotal = result.folder_total || runFilterProgress.value.folderTotal
      runFilterProgress.value.totalPages = totalPages
      runFilterProgress.value.currentPage = currentPage
      runFilterProgress.value.totalProcessed += result.batch_size || 0
      runFilterProgress.value.totalMatched += result.processed || 0
      runFilterProgress.value.allActions.push(...(result.actions || []))
      runFilterProgress.value.batchesCompleted++
      
      const actionsInBatch = result.actions?.length || 0
      
      if ((result.batch_size || 0) === 0) {
        hasMore = false
      } else if (actionsInBatch > 0) {
        if (currentPage > totalPages) hasMore = false
      } else {
        currentPage++
        if (currentPage > totalPages) hasMore = false
      }
      
      if (runFilterProgress.value.batchesCompleted >= 100) hasMore = false
      
      if (hasMore && !runFilterCancelled.value) {
        await new Promise(r => setTimeout(r, 200))
      }
    }
  }
  
  runFilterRunning.value = false
  runFilterResults.value = {
    matched: runFilterProgress.value.totalMatched,
    scanned: runFilterProgress.value.folderTotal || runFilterProgress.value.totalProcessed,
    actions: runFilterProgress.value.allActions,
    cancelled: runFilterCancelled.value,
    batchesCompleted: runFilterProgress.value.batchesCompleted,
    allFolders: runFilterAllFolders.value
  }
  
  // Refresh folders and messages from IMAP after filter actions
  if (runFilterProgress.value.allActions.length > 0) {
    await mailbox.fetchFolders(true)
    await mailbox.fetchMessages()
    mailbox.clearSelection()
  }
}

function cancelRunFilter() {
  runFilterCancelled.value = true
}

function closeRunFilterModal() {
  showRunFilterModal.value = false
  runFilterTarget.value = null
  runFilterResults.value = null
}

// Internal editing filter reference (for list edits)
const internalEditingFilter = ref(null)

// Populate form from data (handles both initialData and editingFilter)
function populateForm(data, isEditing = false) {
  if (!data) return
  
  // Handle legacy format (flat rules array) vs new format (groups)
  let conditions
  if (data.conditions?.groups) {
    conditions = JSON.parse(JSON.stringify(data.conditions)) // Deep clone
  } else if (data.conditions?.rules) {
    // Convert legacy format to groups format
    // Deep clone exceptions to avoid reference issues
    const exceptions = data.conditions.exceptions 
      ? JSON.parse(JSON.stringify(data.conditions.exceptions)) 
      : { match: 'any', rules: [] }
    conditions = {
      match: 'all',
      groups: [{
        match: data.conditions.match || 'all',
        rules: JSON.parse(JSON.stringify(data.conditions.rules)) // Deep clone rules
      }],
      exceptions
    }
  } else {
    conditions = {
      match: 'all',
      groups: [{ match: 'any', rules: [{ field: 'from', operator: 'contains', value: '' }] }],
      exceptions: { match: 'any', rules: [] }
    }
  }
  
  // Ensure exceptions exists
  if (!conditions.exceptions) {
    conditions.exceptions = { match: 'any', rules: [] }
  }
  
  form.value = {
    name: data.name || '',
    enabled: isEditing ? data.enabled : true,
    priority: data.priority || 0,
    stop_processing: data.stop_processing || false,
    conditions,
    actions: data.actions ? [...data.actions] : [{ action: 'move', value: '' }]
  }
}

// Watch for initial data changes (new filter with pre-populated data)
watch(() => props.initialData, (data) => {
  if (data && !props.editingFilter) {
    populateForm(data, false)
  }
}, { immediate: true })

// Watch for editing filter changes
watch(() => props.editingFilter, (filter) => {
  if (filter) {
    populateForm(filter, true)
  }
}, { immediate: true })

// Reset form when modal opens/closes
watch(() => props.show, (isOpen) => {
  if (isOpen) {
    // Populate from editingFilter or initialData, or reset
    if (props.editingFilter) {
      populateForm(props.editingFilter, true)
      internalEditingFilter.value = props.editingFilter
      activeTab.value = 'editor'
    } else if (props.initialData) {
      populateForm(props.initialData, false)
      internalEditingFilter.value = null
      activeTab.value = 'editor'
    } else {
      resetForm()
      internalEditingFilter.value = null
      // If hideListTab or no filters, default to editor tab
      // Otherwise show list tab
      activeTab.value = (props.hideListTab || filtersStore.filters.length === 0) ? 'editor' : 'list'
    }
  } else {
    // Reset state when closing
    showRunNow.value = false
    runResults.value = null
    createdFilterId.value = null
    internalEditingFilter.value = null
    searchQuery.value = ''
    showDeleteConfirm.value = false
    filterToDelete.value = null
    isHoveringModal.value = false
  }
})

onMounted(async () => {
  window.addEventListener('resize', checkMobile)
  if (filtersStore.fields.length === 0) {
    await filtersStore.fetchFilters()
  }
})

function resetForm() {
  form.value = {
    name: '',
    enabled: true,
    priority: 0,
    stop_processing: false,
    conditions: {
      match: 'all',
      groups: [
        {
          match: 'all',
          rules: [{ field: 'from', operator: 'contains', value: '' }]
        }
      ],
      exceptions: {
        match: 'any',
        rules: []
      }
    },
    actions: [{ action: 'move', value: '' }]
  }
}

// Condition group management
function addConditionGroup() {
  form.value.conditions.groups.push({
    match: 'all',
    rules: [{ field: 'from', operator: 'contains', value: '' }]
  })
}

function removeConditionGroup(groupIndex) {
  if (form.value.conditions.groups.length > 1) {
    form.value.conditions.groups.splice(groupIndex, 1)
  }
}

function addConditionToGroup(groupIndex) {
  form.value.conditions.groups[groupIndex].rules.push({ field: 'from', operator: 'contains', value: '' })
}

function removeConditionFromGroup(groupIndex, ruleIndex) {
  const group = form.value.conditions.groups[groupIndex]
  if (group.rules.length > 1) {
    group.rules.splice(ruleIndex, 1)
  } else if (form.value.conditions.groups.length > 1) {
    // If last rule in group, remove the entire group
    form.value.conditions.groups.splice(groupIndex, 1)
  }
}

// Exception management
function addException() {
  if (!form.value.conditions.exceptions) {
    form.value.conditions.exceptions = { match: 'any', rules: [] }
  }
  form.value.conditions.exceptions.rules.push({ field: 'subject', operator: 'contains', value: '' })
}

function removeException(index) {
  form.value.conditions.exceptions.rules.splice(index, 1)
}

function addAction() {
  form.value.actions.push({ action: 'move', value: '' })
}

function removeAction(index) {
  if (form.value.actions.length > 1) {
    form.value.actions.splice(index, 1)
  }
}

// Check if field needs a label selector for value
function fieldNeedsLabelSelector(field) {
  return field === 'has_label'
}

// Check if field needs a linked account selector
function fieldNeedsLinkedAccountSelector(field) {
  return field === 'linked_account'
}

// Create new folder (with optional parent for subfolders)
async function createNewFolder(actionIndex) {
  if (!newFolderName.value.trim()) return
  
  creatingFolder.value = true
  
  // Build full folder path
  const parent = newFolderParent.value || null
  
  // Debug logging
  isDebugEnabled() && console.log('FilterModal createNewFolder:', { name: newFolderName.value.trim(), parent, actionIndex })
  
  const success = await mailbox.createFolder(newFolderName.value.trim(), parent)
  
  creatingFolder.value = false
  
  if (success) {
    toast.success('Folder created')
    // Build full folder name
    let fullName
    if (parent) {
      fullName = parent + '.' + newFolderName.value.trim()
    } else {
      fullName = 'INBOX.' + newFolderName.value.trim()
    }
    
    // Set the new folder as the value
    if (actionIndex !== null && actionIndex !== undefined) {
      form.value.actions[actionIndex].value = fullName
    }
    newFolderName.value = ''
    newFolderParent.value = ''
    showCreateFolder.value = false
    createTargetAction.value = null
  } else {
    toast.error('Failed to create folder')
  }
}

// Create new label
async function createNewLabel(actionIndex) {
  if (!newLabelName.value.trim()) return
  
  creatingLabel.value = true
  const label = await labelsStore.createLabel(newLabelName.value.trim(), newLabelColor.value)
  creatingLabel.value = false
  
  if (label) {
    toast.success('Label created')
    // Set the new label as the value
    if (actionIndex !== null && actionIndex !== undefined) {
      form.value.actions[actionIndex].value = label.id
    }
    newLabelName.value = ''
    newLabelColor.value = '#3b82f6'
    showCreateLabel.value = false
    createTargetAction.value = null
  } else {
    toast.error('Failed to create label')
  }
}

// Open create folder dialog for specific action
function openCreateFolder(actionIndex) {
  createTargetAction.value = actionIndex
  newFolderParent.value = ''
  showCreateFolder.value = true
}

// Open create label dialog for specific action
function openCreateLabel(actionIndex) {
  createTargetAction.value = actionIndex
  showCreateLabel.value = true
}

// Convert groups format to legacy format for backend compatibility
function convertConditionsForSave() {
  const groups = form.value.conditions.groups
  
  // Build exceptions object (filter out empty values)
  const exceptions = form.value.conditions.exceptions
  const filteredExceptions = {
    match: exceptions?.match || 'any',
    rules: (exceptions?.rules || []).filter(r => r.value?.trim() || ['is_empty', 'is_not_empty'].includes(r.operator))
  }
  
  // If only one group, use legacy flat format
  if (groups.length === 1) {
    const result = {
      match: groups[0].match,
      rules: groups[0].rules.filter(r => r.value?.trim() || ['is_empty', 'is_not_empty'].includes(r.operator))
    }
    // Include exceptions if any are defined
    if (filteredExceptions.rules.length > 0) {
      result.exceptions = filteredExceptions
    }
    return result
  }
  
  // Multiple groups - use new format
  const result = {
    match: form.value.conditions.match,
    groups: groups.map(g => ({
      match: g.match,
      rules: g.rules.filter(r => r.value?.trim() || ['is_empty', 'is_not_empty'].includes(r.operator))
    })).filter(g => g.rules.length > 0)
  }
  // Include exceptions if any are defined
  if (filteredExceptions.rules.length > 0) {
    result.exceptions = filteredExceptions
  }
  return result
}

async function saveFilter(andRun = false) {
  if (!form.value.name.trim()) {
    toast.warning('Please enter a filter name')
    return
  }
  
  // Check if any group has valid conditions
  const hasValidCondition = form.value.conditions.groups.some(group =>
    group.rules.some(r => {
      if (r.field === 'has_label' || r.field === 'linked_account') return !!r.value
      if (['is_empty', 'is_not_empty'].includes(r.operator)) return true
      return !!r.value?.trim()
    })
  )
  
  if (!hasValidCondition) {
    toast.warning('Please add at least one condition with a value')
    return
  }
  
  saving.value = true
  
  const data = {
    name: form.value.name,
    enabled: form.value.enabled,
    priority: form.value.priority,
    stop_processing: form.value.stop_processing,
    conditions: convertConditionsForSave(),
    actions: form.value.actions.filter(a => a.action)
  }
  
  let result
  // Check both props.editingFilter and internalEditingFilter (from list tab)
  const editingFilterRef = props.editingFilter || internalEditingFilter.value
  const isEditing = !!editingFilterRef
  
  if (isEditing) {
    // Update existing filter
    result = await filtersStore.updateFilter(editingFilterRef.id, data)
    if (result) {
      toast.success('Filter updated')
    }
  } else {
    // Create new filter
    result = await filtersStore.createFilter(data)
    if (result) {
      toast.success('Filter created')
    }
  }
  
  saving.value = false
  
  if (result) {
    if (andRun) {
      // Show run now panel for both new and edited filters
      createdFilterId.value = isEditing ? editingFilterRef.id : result.id
      showRunNow.value = true
    } else {
      emit('saved', result)
      emit('close')
    }
  } else {
    toast.error(isEditing ? 'Failed to update filter' : 'Failed to create filter')
  }
}

function cancelRunNow() {
  isCancelled.value = true
}

async function runFilterNow() {
  if (!createdFilterId.value) return
  
  isRunning.value = true
  isCancelled.value = false
  runResults.value = null
  runProgress.value = {
    totalProcessed: 0,
    totalMatched: 0,
    allActions: [],
    batchesCompleted: 0,
    folderTotal: 0,
    totalPages: 1,
    currentPage: 1
  }
  
  let currentPage = 1
  let hasMore = true
  const batchSize = 100
  
  while (hasMore && !isCancelled.value) {
    const result = await filtersStore.applySingleFilter(
      createdFilterId.value, 
      runNowFolder.value,
      batchSize,
      currentPage
    )
    
    if (!result) break
    
    // Update progress
    const totalPages = result.total_pages || 1
    runProgress.value.folderTotal = result.folder_total || runProgress.value.folderTotal
    runProgress.value.totalPages = totalPages
    runProgress.value.currentPage = currentPage
    runProgress.value.totalProcessed += result.batch_size || 0
    runProgress.value.totalMatched += result.processed || 0
    runProgress.value.allActions.push(...(result.actions || []))
    runProgress.value.batchesCompleted++
    
    const actionsInBatch = result.actions?.length || 0
    
    if ((result.batch_size || 0) === 0) {
      hasMore = false
    } else if (actionsInBatch > 0) {
      if (currentPage > totalPages) {
        hasMore = false
      }
    } else {
      currentPage++
      if (currentPage > totalPages) {
        hasMore = false
      }
    }
    
    // Safety limit
    if (runProgress.value.batchesCompleted >= 100) hasMore = false
    
    if (hasMore && !isCancelled.value) {
      await new Promise(r => setTimeout(r, 200))
    }
  }
  
  isRunning.value = false
  runResults.value = {
    matched: runProgress.value.totalMatched,
    scanned: runProgress.value.folderTotal || runProgress.value.totalProcessed,
    actions: runProgress.value.allActions,
    cancelled: isCancelled.value,
    batchesCompleted: runProgress.value.batchesCompleted
  }
  
  // Update filter history
  filtersStore.updateFilterRunHistory(createdFilterId.value, {
    folder: runNowFolder.value,
    matched: runProgress.value.totalMatched,
    actionsCount: runProgress.value.allActions.length,
    success: !isCancelled.value
  })
  
  // Refresh from IMAP after filter actions
  if (runProgress.value.allActions.length > 0) {
    await mailbox.fetchFolders(true)
    await mailbox.fetchMessages()
  }
}

// Draggable modal functions
function startDrag(e) {
  if (e.target.closest('input, select, button')) return
  isDragging.value = true
  const rect = e.currentTarget.parentElement.getBoundingClientRect()
  dragOffset.value = {
    x: e.clientX - rect.left,
    y: e.clientY - rect.top
  }
  document.addEventListener('mousemove', onDrag)
  document.addEventListener('mouseup', stopDrag)
}

function onDrag(e) {
  if (!isDragging.value) return
  modalPosition.value = {
    x: e.clientX - dragOffset.value.x,
    y: e.clientY - dragOffset.value.y
  }
}

function stopDrag() {
  isDragging.value = false
  document.removeEventListener('mousemove', onDrag)
  document.removeEventListener('mouseup', stopDrag)
}

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
  document.removeEventListener('mousemove', onDrag)
  document.removeEventListener('mouseup', stopDrag)
})

function closeModal() {
  // Emit saved if we created a filter (via Run Now flow)
  if (createdFilterId.value) {
    emit('saved', { id: createdFilterId.value })
  }
  showRunNow.value = false
  runResults.value = null
  createdFilterId.value = null
  showCreateFolder.value = false
  showCreateLabel.value = false
  // Reset modal position for next open
  modalPosition.value = { x: null, y: null }
  emit('close')
}

function getActionInfo(action) {
  return filtersStore.actions.find(a => a.id === action.action) || {}
}

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

// Filtered folders for dropdowns
const filteredFolders = computed(() => {
  return mailbox.folders.filter(f => !isHiddenFolder(f))
})

function getFolderName(folderName) {
  if (folderName === 'INBOX') return 'Inbox'
  if (folderName?.startsWith('INBOX.')) {
    let displayName = folderName.slice(6).replace(/\./g, ' / ')
    displayName = displayName.replace('Deleted Items', 'Trash')
    return displayName
  }
  let displayName = folderName?.replace(/\./g, ' / ') || folderName
  if (displayName) {
    displayName = displayName.replace('Deleted Items', 'Trash')
  }
  return displayName
}

function getLabelName(labelId) {
  const label = labelsStore.labels.find(l => l.id === labelId || l.id === parseInt(labelId))
  return label?.name || labelId
}

const colorOptions = computed(() => {
  return Object.entries(labelsStore.colors).map(([name, hex]) => ({
    name,
    hex
  }))
})

// Get folders that can be parents (exclude special folders)
const parentFolderOptions = computed(() => {
  return mailbox.folders.filter(f => 
    !['trash', 'spam', 'junk', 'drafts'].includes(f.type) &&
    f.name !== 'INBOX'
  )
})
</script>

<template>
  <Teleport to="body">
    <!-- Backdrop blur for focus enhancement (only when hovering modal) -->
    <Transition name="backdrop-blur">
      <div 
        v-if="show && isHoveringModal"
        class="fixed inset-0 z-[99] backdrop-blur-[2px] bg-black/5 dark:bg-black/10 pointer-events-none"
      ></div>
    </Transition>
    
    <!-- Draggable Filter Modal -->
    <Transition name="modal-slide">
      <div 
        v-if="show"
        @mouseenter="isHoveringModal = true"
        @mouseleave="isHoveringModal = false"
        :class="[
          'fixed z-[100] overflow-hidden bg-white dark:bg-surface-800 flex flex-col border border-surface-300 dark:border-surface-600',
          isMobile
            ? 'inset-0 rounded-none'
            : 'w-full max-w-3xl max-h-[90vh] rounded-2xl shadow-2xl'
        ]"
        :style="!isMobile ? (modalPosition.x !== null ? { left: modalPosition.x + 'px', top: modalPosition.y + 'px' } : { top: '50%', left: '50%', transform: 'translate(-50%, -50%)' }) : {}"
      >
      <!-- Draggable Header -->
      <div 
        @mousedown="!isMobile && startDrag($event)"
        :class="[
          'px-4 md:px-6 flex items-center justify-between select-none bg-surface-50 dark:bg-surface-900',
          isMobile ? 'pt-14 pb-3 border-b border-surface-200 dark:border-surface-700' : 'cursor-move rounded-t-2xl',
          !isMobile && hideListTab ? 'py-4 border-b border-surface-200 dark:border-surface-700' : !isMobile ? 'py-3' : ''
        ]"
      >
        <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
          {{ hideListTab ? ((internalEditingFilter || editingFilter) ? 'Edit Filter' : 'New Filter') : 'Filters' }}
          <span v-if="!isMobile" class="material-symbols-rounded text-surface-400 text-sm">drag_indicator</span>
        </h3>
        <button @click="closeModal" class="btn-ghost btn-icon">
          <span class="material-symbols-rounded">close</span>
        </button>
      </div>
      
      <!-- Tabs (hidden when hideListTab is true) -->
      <div v-if="!hideListTab" class="flex border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900">
        <button 
          @click="activeTab = 'editor'; resetForm(); internalEditingFilter = null"
          :class="[
            'flex-1 px-4 py-3 text-sm font-medium transition-colors relative',
            activeTab === 'editor' 
              ? 'text-primary-600 dark:text-primary-400' 
              : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
        >
          <span class="flex items-center justify-center gap-2">
            <span class="material-symbols-rounded text-lg">add</span>
            {{ internalEditingFilter || editingFilter ? 'Edit Filter' : 'New Filter' }}
          </span>
          <span 
            v-if="activeTab === 'editor'"
            class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary-500"
          ></span>
        </button>
        <button 
          @click="activeTab = 'list'"
          :class="[
            'flex-1 px-4 py-3 text-sm font-medium transition-colors relative',
            activeTab === 'list' 
              ? 'text-primary-600 dark:text-primary-400' 
              : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
        >
          <span class="flex items-center justify-center gap-2">
            <span class="material-symbols-rounded text-lg">filter_list</span>
            Manage Filters
            <span v-if="filtersStore.filters.length > 0" class="px-1.5 py-0.5 text-xs rounded-full bg-surface-200 dark:bg-surface-700">
              {{ filtersStore.filters.length }}
            </span>
          </span>
          <span 
            v-if="activeTab === 'list'"
            class="absolute bottom-0 left-0 right-0 h-0.5 bg-primary-500"
          ></span>
        </button>
      </div>
        
        <!-- Editor Tab Content -->
        <div v-if="activeTab === 'editor'" class="flex-1 overflow-y-auto p-4 md:p-6 space-y-6">
          <!-- Name -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
              Filter Name
            </label>
            <input v-model="form.name" type="text" class="input" placeholder="e.g., Move newsletters to Archive" />
          </div>
          
          <!-- Help Section -->
          <div class="rounded-xl border border-primary-200 dark:border-primary-800 bg-primary-50 dark:bg-primary-900/20">
            <button 
              @click="showFilterHelp = !showFilterHelp"
              class="w-full px-4 py-3 flex items-center justify-between text-left"
            >
              <div class="flex items-center gap-2 text-primary-600 dark:text-primary-400">
                <span class="material-symbols-rounded">help</span>
                <span class="text-sm font-medium">How do filters work?</span>
              </div>
              <span class="material-symbols-rounded text-primary-500 transition-transform" :class="showFilterHelp ? 'rotate-180' : ''">
                expand_more
              </span>
            </button>
            
            <div v-if="showFilterHelp" class="px-4 pb-4 text-sm text-surface-700 dark:text-surface-300 space-y-4">
              <div class="border-t border-primary-200 dark:border-primary-800 pt-4">
                <h4 class="font-semibold text-surface-900 dark:text-surface-100 mb-2">Understanding Conditions</h4>
                <ul class="space-y-1 text-xs">
                  <li><strong class="text-primary-600">AND:</strong> ALL conditions must match</li>
                  <li><strong class="text-primary-600">OR:</strong> ANY condition can match</li>
                  <li><strong class="text-orange-500">Exceptions:</strong> Exclude messages even if conditions match</li>
                </ul>
              </div>
              
              <div class="bg-white dark:bg-surface-800 rounded-lg p-3 border border-surface-200 dark:border-surface-700">
                <h5 class="font-semibold text-green-600 dark:text-green-400 mb-2 flex items-center gap-1">
                  <span class="material-symbols-rounded text-base">check_circle</span>
                  Example 1: Filter newsletters from multiple senders
                </h5>
                <div class="text-xs space-y-1 mb-2">
                  <p class="text-surface-600 dark:text-surface-400">Goal: Move all newsletters to a folder</p>
                </div>
                <div class="bg-surface-100 dark:bg-surface-700 rounded p-2 text-xs font-mono">
                  <div class="text-primary-600">Match ANY (OR):</div>
                  <div class="pl-3">From contains: newsletter@company1.com</div>
                  <div class="pl-3">From contains: news@company2.com</div>
                  <div class="pl-3">Subject contains: [Newsletter]</div>
                  <div class="mt-1 text-green-600">Action: Move to "Newsletters"</div>
                </div>
              </div>
              
              <div class="bg-white dark:bg-surface-800 rounded-lg p-3 border border-surface-200 dark:border-surface-700">
                <h5 class="font-semibold text-green-600 dark:text-green-400 mb-2 flex items-center gap-1">
                  <span class="material-symbols-rounded text-base">check_circle</span>
                  Example 2: Using Exceptions to exclude specific emails
                </h5>
                <div class="text-xs space-y-1 mb-2">
                  <p class="text-surface-600 dark:text-surface-400">Goal: Archive emails from company, BUT keep alerts in Inbox</p>
                </div>
                <div class="bg-surface-100 dark:bg-surface-700 rounded p-2 text-xs font-mono">
                  <div class="text-primary-600">Match ALL (AND):</div>
                  <div class="pl-3">From contains: @company.com</div>
                  <div class="mt-2 text-orange-500">EXCEPT if ANY:</div>
                  <div class="pl-3">Subject contains: [URGENT]</div>
                  <div class="pl-3">Subject contains: [ALERT]</div>
                  <div class="mt-1 text-green-600">Action: Move to "Archive"</div>
                </div>
                <p class="text-xs text-surface-500 mt-2 italic">
                  Result: Emails from @company.com go to Archive, except those with [URGENT] or [ALERT] in subject stay in Inbox.
                </p>
              </div>
            </div>
          </div>
          
          <!-- Conditions with Groups -->
          <div>
            <div class="flex items-center justify-between gap-2 mb-3">
              <label class="text-sm font-medium text-surface-700 dark:text-surface-300 flex-shrink-0">
                Conditions
              </label>
              <div v-if="form.conditions.groups.length > 1" class="flex items-center gap-2">
                <span class="text-xs text-surface-500 hidden sm:inline">Groups combined with:</span>
                <select v-model="form.conditions.match" class="select-input text-xs py-1 px-2">
                  <option value="all">AND</option>
                  <option value="any">OR</option>
                </select>
              </div>
            </div>
            
            <!-- Condition Groups -->
            <div class="space-y-3">
              <template v-for="(group, groupIndex) in form.conditions.groups" :key="groupIndex">
                <!-- Group Card -->
                <div class="p-4 rounded-xl border-2 border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900">
                  <!-- Group header -->
                  <div class="flex items-center justify-between gap-2 mb-3">
                    <div class="flex items-center gap-2 min-w-0">
                      <span class="text-xs font-medium text-surface-500 uppercase flex-shrink-0">
                        {{ form.conditions.groups.length > 1 ? `Group ${groupIndex + 1}` : 'Match' }}
                      </span>
                      <select v-model="group.match" class="select-input text-xs py-1 px-2 min-w-0">
                        <option value="all">ALL conditions (AND)</option>
                        <option value="any">ANY condition (OR)</option>
                      </select>
                    </div>
                    <button 
                      v-if="form.conditions.groups.length > 1"
                      @click="removeConditionGroup(groupIndex)" 
                      class="btn-ghost btn-icon btn-sm text-red-500"
                      title="Remove group"
                    >
                      <span class="material-symbols-rounded text-base">delete</span>
                    </button>
                  </div>
                  
                  <!-- Rules in group -->
                  <div class="space-y-1">
                    <div v-for="(rule, ruleIndex) in group.rules" :key="ruleIndex" class="relative">
                      <div :class="isMobile ? 'space-y-2' : 'flex gap-2 items-start'">
                        <div :class="isMobile ? 'flex gap-2' : 'contents'">
                          <select v-model="rule.field" :class="['select-input text-sm', isMobile ? 'flex-1' : 'w-32']">
                            <option v-for="field in extendedFields" :key="field.id" :value="field.id">
                              {{ field.name }}
                            </option>
                          </select>
                          
                          <select v-model="rule.operator" :class="['select-input text-sm', isMobile ? 'flex-1' : 'w-36']">
                            <option v-for="op in filtersStore.operators" :key="op.id" :value="op.id">
                              {{ op.name }}
                            </option>
                          </select>
                        </div>
                        
                        <div :class="isMobile ? 'flex gap-2' : 'contents'">
                          <!-- Label selector for has_label field -->
                          <select
                            v-if="fieldNeedsLabelSelector(rule.field)"
                            v-model="rule.value"
                            class="select-input flex-1 text-sm"
                          >
                            <option value="">Select label...</option>
                            <option v-for="label in labelsStore.labels" :key="label.id" :value="label.name">
                              {{ label.name }}
                            </option>
                          </select>
                          
                          <!-- Linked account selector -->
                          <select
                            v-else-if="fieldNeedsLinkedAccountSelector(rule.field)"
                            v-model="rule.value"
                            class="select-input flex-1 text-sm"
                          >
                            <option value="">Select linked account...</option>
                            <option v-for="acc in accountsStore.linkedAccounts" :key="acc.id" :value="acc.account_email">
                              {{ acc.account_email }}
                            </option>
                          </select>
                          
                          <!-- Regular text input for other fields -->
                          <input 
                            v-else
                            v-model="rule.value" 
                            type="text" 
                            class="input flex-1 text-sm" 
                            placeholder="Value..."
                            :disabled="['is_empty', 'is_not_empty'].includes(rule.operator)"
                          />
                          
                          <button 
                            @click="removeConditionFromGroup(groupIndex, ruleIndex)" 
                            class="btn-ghost btn-icon btn-sm text-red-500 flex-shrink-0"
                            title="Remove condition"
                          >
                            <span class="material-symbols-rounded">remove</span>
                          </button>
                        </div>
                      </div>
                      
                      <!-- AND/OR connector between conditions (clickable to toggle) -->
                      <div 
                        v-if="ruleIndex < group.rules.length - 1"
                        class="flex items-center justify-center py-1"
                      >
                        <button
                          @click="group.match = group.match === 'all' ? 'any' : 'all'"
                          class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-surface-200 dark:bg-surface-700 text-surface-500 dark:text-surface-400 hover:bg-surface-300 dark:hover:bg-surface-600 transition-colors cursor-pointer"
                          title="Click to toggle between AND/OR"
                        >
                          {{ group.match === 'all' ? 'AND' : 'OR' }}
                        </button>
                      </div>
                    </div>
                  </div>
                  
                  <button @click="addConditionToGroup(groupIndex)" class="btn-ghost btn-sm mt-2 text-primary-500">
                    <span class="material-symbols-rounded">add</span>
                    Add Condition
                  </button>
                </div>
                
                <!-- AND/OR connector between groups (separate element, not inside card) -->
                <div 
                  v-if="groupIndex < form.conditions.groups.length - 1"
                  class="flex items-center justify-center py-1"
                >
                  <button 
                    @click="form.conditions.match = form.conditions.match === 'all' ? 'any' : 'all'"
                    class="px-4 py-1.5 rounded-full text-xs font-bold bg-primary-500 text-white shadow-md hover:bg-primary-600 transition-colors cursor-pointer"
                    title="Click to toggle between AND/OR"
                  >
                    {{ form.conditions.match === 'all' ? 'AND' : 'OR' }}
                  </button>
                </div>
              </template>
            </div>
            
            <button @click="addConditionGroup" class="btn-ghost btn-sm mt-4 text-primary-500">
              <span class="material-symbols-rounded">add_circle</span>
              Add Condition Group
            </button>
          </div>
          
          <!-- Exceptions Section -->
          <div>
            <div class="flex items-center justify-between mb-3">
              <label class="text-sm font-medium text-surface-700 dark:text-surface-300 flex items-center gap-2">
                <span class="material-symbols-rounded text-orange-500">block</span>
                Exceptions
                <span class="text-xs text-surface-500 font-normal">(exclude if any match)</span>
              </label>
              <button 
                v-if="form.conditions.exceptions?.rules?.length === 0"
                @click="addException"
                class="btn-ghost btn-sm text-orange-500"
              >
                <span class="material-symbols-rounded">add</span>
                Add Exception
              </button>
            </div>
            
            <div 
              v-if="form.conditions.exceptions?.rules?.length > 0"
              class="p-4 rounded-xl border-2 border-dashed border-orange-300 dark:border-orange-700 bg-orange-50 dark:bg-orange-900/20"
            >
              <p class="text-xs text-orange-600 dark:text-orange-400 mb-3">
                Messages matching the conditions above will be EXCLUDED if any of these exceptions match:
              </p>
              
              <div class="space-y-2">
                <div 
                  v-for="(exception, index) in form.conditions.exceptions.rules" 
                  :key="index" 
                  :class="isMobile ? 'space-y-2' : 'flex gap-2 items-start'"
                >
                  <div :class="isMobile ? 'flex gap-2' : 'contents'">
                    <select v-model="exception.field" :class="['select-input text-sm', isMobile ? 'flex-1' : 'w-32']">
                      <option v-for="field in extendedFields" :key="field.id" :value="field.id">
                        {{ field.name }}
                      </option>
                    </select>
                    
                    <select v-model="exception.operator" :class="['select-input text-sm', isMobile ? 'flex-1' : 'w-36']">
                      <option v-for="op in filtersStore.operators" :key="op.id" :value="op.id">
                        {{ op.name }}
                      </option>
                    </select>
                  </div>
                  
                  <div :class="isMobile ? 'flex gap-2' : 'contents'">
                    <!-- Label selector for has_label -->
                    <select
                      v-if="fieldNeedsLabelSelector(exception.field)"
                      v-model="exception.value"
                      class="select-input flex-1 text-sm"
                    >
                      <option value="">Select label...</option>
                      <option v-for="label in labelsStore.labels" :key="label.id" :value="label.name">
                        {{ label.name }}
                      </option>
                    </select>
                    
                    <!-- Linked account selector -->
                    <select
                      v-else-if="fieldNeedsLinkedAccountSelector(exception.field)"
                      v-model="exception.value"
                      class="select-input flex-1 text-sm"
                    >
                      <option value="">Select linked account...</option>
                      <option v-for="acc in accountsStore.linkedAccounts" :key="acc.id" :value="acc.account_email">
                        {{ acc.account_email }}
                      </option>
                    </select>
                    
                    <!-- Regular text input -->
                    <input 
                      v-else
                      v-model="exception.value" 
                      type="text" 
                      class="input flex-1 text-sm" 
                      placeholder="Value..."
                      :disabled="['is_empty', 'is_not_empty'].includes(exception.operator)"
                    />
                    
                    <button 
                      @click="removeException(index)" 
                      class="btn-ghost btn-icon btn-sm text-red-500 flex-shrink-0"
                      title="Remove exception"
                    >
                      <span class="material-symbols-rounded">remove</span>
                    </button>
                  </div>
                </div>
                
                <!-- OR connector between exceptions -->
                <div 
                  v-if="form.conditions.exceptions.rules.length > 1"
                  class="flex items-center justify-center py-1"
                >
                  <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-orange-200 dark:bg-orange-800 text-orange-600 dark:text-orange-300">
                    OR
                  </span>
                </div>
              </div>
              
              <button @click="addException" class="btn-ghost btn-sm mt-3 text-orange-500">
                <span class="material-symbols-rounded">add</span>
                Add Exception
              </button>
            </div>
            
            <p 
              v-else 
              class="text-xs text-surface-500 italic"
            >
              No exceptions defined. Click "Add Exception" to exclude specific messages.
            </p>
          </div>
          
          <!-- Actions -->
          <div>
            <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">
              Actions
            </label>
            
            <!-- Warning for invalid actions -->
            <div v-if="formHasInvalidActions" class="mb-3 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg flex items-center gap-2 text-red-600 dark:text-red-400">
              <span class="material-symbols-rounded">warning</span>
              <span class="text-sm">Some actions are missing required values. This filter won't work correctly.</span>
            </div>
            
            <div class="space-y-2">
              <div v-for="(action, index) in form.actions" :key="index" :class="isMobile ? 'space-y-2' : 'flex gap-2 items-start'">
                <select v-model="action.action" :class="['select-input text-sm', isMobile ? 'w-full' : 'w-40']">
                  <option v-for="act in filtersStore.actions" :key="act.id" :value="act.id">
                    {{ act.name }}
                  </option>
                </select>
                
                <div :class="isMobile ? 'flex gap-2' : 'contents'">
                  <!-- Folder selector with create option -->
                  <div v-if="getActionInfo(action).valueType === 'folder'" class="flex-1 flex gap-1">
                    <select 
                      v-model="action.value" 
                      :class="['select-input flex-1 text-sm', !action.value ? 'select-error' : '']"
                    >
                      <option value="">Select folder...</option>
                      <option v-for="folder in filteredFolders" :key="folder.name" :value="folder.name">
                        {{ getFolderName(folder.name) }}
                      </option>
                    </select>
                    <button 
                      @click="openCreateFolder(index)"
                      class="btn-ghost btn-icon btn-sm text-primary-500 flex-shrink-0"
                      title="Create new folder"
                    >
                      <span class="material-symbols-rounded">create_new_folder</span>
                    </button>
                  </div>
                  
                  <!-- Label selector with create option -->
                  <div v-else-if="getActionInfo(action).valueType === 'label'" class="flex-1 flex gap-1">
                    <select 
                      v-model="action.value" 
                      :class="['select-input flex-1 text-sm', !action.value ? 'select-error' : '']"
                    >
                      <option value="">Select label...</option>
                      <option v-for="label in labelsStore.labels" :key="label.id" :value="label.id">
                        {{ label.name }}
                      </option>
                    </select>
                    <button 
                      @click="openCreateLabel(index)"
                      class="btn-ghost btn-icon btn-sm text-primary-500 flex-shrink-0"
                      title="Create new label"
                    >
                      <span class="material-symbols-rounded">new_label</span>
                    </button>
                  </div>
                  
                  <!-- No value needed -->
                  <div v-else class="flex-1"></div>
                  
                  <button 
                    @click="removeAction(index)" 
                    class="btn-ghost btn-icon btn-sm text-red-500 flex-shrink-0"
                    :disabled="form.actions.length <= 1"
                  >
                    <span class="material-symbols-rounded">remove</span>
                  </button>
                </div>
              </div>
            </div>
            
            <button @click="addAction" class="btn-ghost btn-sm mt-2 text-primary-500">
              <span class="material-symbols-rounded">add</span>
              Add Action
            </button>
          </div>
          
          <!-- Options with toggle switches -->
          <div class="flex items-center gap-4 md:gap-8 pt-4 border-t border-surface-200 dark:border-surface-700 flex-wrap">
            <label class="flex items-center gap-3 cursor-pointer">
              <button
                type="button"
                @click="form.enabled = !form.enabled"
                :class="['w-11 h-6 rounded-full transition-colors relative shrink-0', form.enabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
              >
                <span 
                  :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', form.enabled ? 'translate-x-5' : 'translate-x-0']"
                ></span>
              </button>
              <span class="text-sm text-surface-700 dark:text-surface-300">Enabled</span>
            </label>
            
            <label class="flex items-center gap-3 cursor-pointer">
              <button
                type="button"
                @click="form.stop_processing = !form.stop_processing"
                :class="['w-11 h-6 rounded-full transition-colors relative shrink-0', form.stop_processing ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600']"
              >
                <span 
                  :class="['absolute top-1 left-1 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200', form.stop_processing ? 'translate-x-5' : 'translate-x-0']"
                ></span>
              </button>
              <span class="text-sm text-surface-700 dark:text-surface-300">Stop processing other filters</span>
            </label>
          </div>
        </div>
        
        <!-- List Tab Content -->
        <div v-if="activeTab === 'list'" class="flex-1 overflow-y-auto">
          <!-- Search -->
          <div class="p-4 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-900/50">
            <div class="relative">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-rounded text-surface-400">search</span>
              <input 
                v-model="searchQuery"
                type="text" 
                placeholder="Search filters..." 
                class="input pl-10 w-full"
              />
            </div>
          </div>
          
          <!-- Filter List -->
          <div class="p-4 space-y-3">
            <!-- Empty state -->
            <div v-if="filtersStore.filters.length === 0" class="text-center py-12">
              <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600 mb-3">filter_alt_off</span>
              <p class="text-surface-500 dark:text-surface-400 mb-4">No filters yet</p>
              <button @click="activeTab = 'editor'; resetForm()" class="btn-primary">
                <span class="material-symbols-rounded">add</span>
                Create your first filter
              </button>
            </div>
            
            <!-- No search results -->
            <div v-else-if="searchQuery && filteredFilters.length === 0" class="text-center py-8">
              <span class="material-symbols-rounded text-4xl text-surface-400 mb-2">search_off</span>
              <p class="text-surface-500">No filters match "{{ searchQuery }}"</p>
              <button @click="searchQuery = ''" class="btn-ghost btn-sm mt-2 text-primary-500">
                Clear search
              </button>
            </div>
            
            <!-- Filter items -->
            <div
              v-for="filter in filteredFilters"
              :key="filter.id"
              class="p-4 rounded-xl bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700 hover:border-primary-300 dark:hover:border-primary-700 transition-colors"
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
                    <h4 class="font-medium text-surface-900 dark:text-surface-100 truncate">{{ filter.name }}</h4>
                    <span 
                      v-if="hasInvalidActions(filter)" 
                      class="text-red-500 flex items-center gap-1"
                      :title="getInvalidActionMessages(filter).join(', ')"
                    >
                      <span class="material-symbols-rounded text-base">error</span>
                    </span>
                  </div>
                  <div class="text-xs text-surface-500 mt-1 flex items-center gap-2 flex-wrap">
                    <span>{{ getConditionCount(filter) }} condition(s)</span>
                    <span class="text-surface-300">|</span>
                    <span>{{ filter.actions?.length || 0 }} action(s)</span>
                    <span v-if="filter.stop_processing" class="text-amber-500 flex items-center gap-0.5">
                      <span class="material-symbols-rounded text-xs">block</span>
                      Stops
                    </span>
                  </div>
                  <!-- Show action targets -->
                  <div class="mt-1 text-xs text-surface-600 dark:text-surface-300 font-mono">
                    {{ filter.actions?.map(a => `${a.action}:${a.value || 'EMPTY'}`).join(', ') }}
                  </div>
                  <!-- Invalid actions warning -->
                  <div v-if="hasInvalidActions(filter)" class="mt-1 text-xs text-red-500 flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm">warning</span>
                    {{ getInvalidActionMessages(filter).join(', ') }}
                  </div>
                </div>
                
                <!-- Actions -->
                <div class="flex items-center gap-1">
                  <button @click="openRunFilterModal(filter)" class="btn-ghost btn-icon btn-sm text-green-500" title="Run filter on existing messages">
                    <span class="material-symbols-rounded">play_arrow</span>
                  </button>
                  <button @click="duplicateFilterFromList(filter)" class="btn-ghost btn-icon btn-sm text-surface-500" title="Duplicate">
                    <span class="material-symbols-rounded">content_copy</span>
                  </button>
                  <button @click="editFilterFromList(filter)" class="btn-ghost btn-icon btn-sm text-primary-500" title="Edit">
                    <span class="material-symbols-rounded">edit</span>
                  </button>
                  <button @click="confirmDelete(filter)" class="btn-ghost btn-icon btn-sm text-red-500" title="Delete">
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Delete Confirmation Inline -->
          <div v-if="showDeleteConfirm" class="fixed inset-0 z-[101] flex items-center justify-center bg-black/50">
            <div class="bg-white dark:bg-surface-800 rounded-xl p-6 max-w-sm mx-4 shadow-2xl">
              <div class="flex items-center gap-3 mb-4">
                <span class="material-symbols-rounded text-2xl text-red-500">warning</span>
                <h4 class="font-semibold text-surface-900 dark:text-surface-100">Delete Filter</h4>
              </div>
              <p class="text-surface-600 dark:text-surface-400 mb-6">
                Are you sure you want to delete "{{ filterToDelete?.name }}"?
              </p>
              <div class="flex justify-end gap-3">
                <button @click="showDeleteConfirm = false; filterToDelete = null" class="btn-ghost">
                  Cancel
                </button>
                <button @click="deleteFilter" class="btn-primary bg-red-500 hover:bg-red-600">
                  Delete
                </button>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Footer (only for editor tab) -->
        <div v-if="activeTab === 'editor' && !showRunNow" :class="['px-4 md:px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex gap-2 md:gap-3', isMobile ? 'flex-col-reverse pb-8' : 'justify-end']">
          <button v-if="hideListTab" @click="closeModal" :class="isMobile ? 'btn-ghost w-full justify-center' : 'btn-ghost'">
            Cancel
          </button>
          <button v-else @click="activeTab = 'list'" :class="isMobile ? 'btn-ghost w-full justify-center' : 'btn-ghost'">
            <span class="material-symbols-rounded">arrow_back</span>
            Back
          </button>
          <button 
            @click="saveFilter(true)" 
            :disabled="saving" 
            :class="isMobile ? 'btn-secondary w-full justify-center' : 'btn-secondary'"
          >
            <span v-if="saving" class="spinner"></span>
            <span class="material-symbols-rounded">play_arrow</span>
            {{ (editingFilter || internalEditingFilter) ? 'Save & Run Now' : 'Create & Run Now' }}
          </button>
          <button @click="saveFilter(false)" :disabled="saving" :class="isMobile ? 'btn-primary w-full justify-center' : 'btn-primary'">
            <span v-if="saving" class="spinner"></span>
            <span class="material-symbols-rounded">save</span>
            {{ (editingFilter || internalEditingFilter) ? 'Save Changes' : 'Create Filter' }}
          </button>
        </div>
        
        <!-- Run Now Panel (shown after creating with Run Now) -->
        <div v-if="activeTab === 'editor' && showRunNow" class="border-t border-surface-200 dark:border-surface-700">
          <div class="p-6 space-y-4">
            <div class="flex items-center gap-3 text-primary-500">
              <span class="material-symbols-rounded text-2xl">check_circle</span>
              <span class="font-medium">{{ (editingFilter || internalEditingFilter) ? 'Filter updated successfully' : 'Filter created successfully' }}</span>
            </div>
            
            <!-- Run Now Options (before running) -->
            <div v-if="!isRunning && !runResults" class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  Run filter on folder
                </label>
                <select v-model="runNowFolder" class="select-input w-full">
                  <option v-for="folder in filteredFolders" :key="folder.name" :value="folder.name">
                    {{ getFolderName(folder.name) }}
                  </option>
                </select>
              </div>
              
              <div class="bg-surface-100 dark:bg-surface-700 rounded-lg p-3">
                <p class="text-sm text-surface-600 dark:text-surface-400">
                  This will apply your new filter to all existing messages in <span class="font-medium">{{ getFolderName(runNowFolder) }}</span>
                </p>
              </div>
            </div>
            
            <!-- Running with progress -->
            <div v-if="isRunning" class="flex flex-col items-center py-4">
              <span class="spinner text-primary-500 w-10 h-10 mb-4"></span>
              <p class="text-surface-900 dark:text-surface-100 font-medium mb-2">
                Processing {{ getFolderName(runNowFolder) }}...
              </p>
              <div class="text-sm text-surface-500 text-center space-y-1">
                <p>Page {{ runProgress.currentPage }} of {{ runProgress.totalPages }}</p>
                <p>{{ Math.min(runProgress.currentPage * 100, runProgress.folderTotal) }} of {{ runProgress.folderTotal }} messages scanned</p>
                <p class="text-primary-500 font-medium">{{ runProgress.totalMatched }} matched so far</p>
                <p v-if="runProgress.allActions.length > 0" class="text-green-500">
                  {{ runProgress.allActions.length }} action(s) applied
                </p>
              </div>
              <!-- Progress bar -->
              <div class="w-full max-w-xs mt-4 bg-surface-200 dark:bg-surface-700 rounded-full h-2">
                <div 
                  class="bg-primary-500 h-2 rounded-full transition-all duration-300"
                  :style="{ width: `${Math.min(100, (runProgress.currentPage / Math.max(1, runProgress.totalPages)) * 100)}%` }"
                ></div>
              </div>
              <p class="text-xs text-surface-400 mt-2">
                Batch {{ runProgress.batchesCompleted }} completed
              </p>
            </div>
            
            <!-- Results -->
            <div v-if="runResults && !isRunning" class="space-y-3">
              <div class="text-center mb-4">
                <span :class="['material-symbols-rounded text-4xl mb-2', runResults.cancelled ? 'text-amber-500' : (runResults.actions.length > 0 ? 'text-primary-500' : 'text-surface-400')]">
                  {{ runResults.cancelled ? 'cancel' : (runResults.actions.length > 0 ? 'check_circle' : 'info') }}
                </span>
                <p class="text-lg font-medium text-surface-900 dark:text-surface-100">
                  {{ runResults.cancelled ? 'Cancelled' : 'Complete' }} — {{ runResults.matched }} messages matched
                </p>
                <p class="text-sm text-surface-500 mt-1">
                  Scanned {{ runResults.scanned }} messages in {{ getFolderName(runNowFolder) }}
                  <span v-if="runResults.batchesCompleted > 1">({{ runResults.batchesCompleted }} batches)</span>
                </p>
              </div>
              
              <div v-if="runResults.actions.length > 0" class="max-h-40 overflow-y-auto bg-surface-100 dark:bg-surface-700 rounded-lg p-3 text-sm">
                <div v-for="(action, i) in runResults.actions.slice(0, 10)" :key="i" class="py-1 flex items-center gap-2">
                  <span class="material-symbols-rounded text-primary-500 text-base">check</span>
                  <span class="text-surface-700 dark:text-surface-300">{{ action.action }}</span>
                  <span class="text-surface-500 truncate">"{{ action.subject?.substring(0, 30) }}{{ action.subject?.length > 30 ? '...' : '' }}"</span>
                </div>
                <p v-if="runResults.actions.length > 10" class="text-xs text-surface-500 mt-2">
                  + {{ runResults.actions.length - 10 }} more actions
                </p>
              </div>
              
              <p v-else class="text-sm text-surface-500 bg-surface-100 dark:bg-surface-700 rounded-lg p-3 text-center">
                No messages matched your filter conditions in {{ getFolderName(runNowFolder) }}
              </p>
            </div>
          </div>
          
          <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-3">
            <!-- Cancel button while running -->
            <button v-if="isRunning" @click="cancelRunNow" class="btn-secondary text-red-500 border-red-300 hover:bg-red-50 dark:hover:bg-red-900/20">
              <span class="material-symbols-rounded">stop</span>
              Cancel
            </button>
            
            <button v-if="!isRunning && !runResults" @click="closeModal" class="btn-ghost">
              Skip
            </button>
            <button v-if="!isRunning && !runResults" @click="runFilterNow" class="btn-primary">
              <span class="material-symbols-rounded">play_arrow</span>
              Run Now
            </button>
            <button v-if="runResults && !isRunning" @click="closeModal" class="btn-primary">
              Done
            </button>
          </div>
        </div>
      </div>
    </Transition>
    
    <!-- Create Folder Modal - MUST be outside the main modal's Transition to avoid transform stacking context issues -->
    <Transition name="fade">
      <div v-if="showCreateFolder" class="fixed inset-0 z-[110] flex items-center justify-center p-4 bg-black/50" @mousedown.self="showCreateFolder = false">
        <div class="w-full max-w-sm bg-white dark:bg-surface-800 rounded-xl shadow-2xl p-6">
          <h4 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4">Create New Folder</h4>
          
          <!-- Parent folder selection (optional for subfolders) -->
          <div class="mb-3">
            <label class="block text-sm text-surface-600 dark:text-surface-400 mb-1">Parent folder (optional)</label>
            <select v-model="newFolderParent" class="select-input w-full text-sm">
              <option value="">None (create in root)</option>
              <option value="INBOX">Inbox</option>
              <option v-for="folder in parentFolderOptions" :key="folder.name" :value="folder.name">
                {{ getFolderName(folder.name) }}
              </option>
            </select>
          </div>
          
          <div class="mb-4">
            <label class="block text-sm text-surface-600 dark:text-surface-400 mb-1">Folder name</label>
            <input
              v-model="newFolderName"
              type="text"
              class="input w-full"
              placeholder="Folder name..."
              @keyup.enter="createNewFolder(createTargetAction)"
              autofocus
            />
          </div>
          
          <!-- Preview of full path -->
          <div v-if="newFolderName.trim()" class="mb-4 p-2 bg-surface-100 dark:bg-surface-700 rounded-lg">
            <p class="text-xs text-surface-500">Full path:</p>
            <p class="text-sm text-surface-700 dark:text-surface-300 font-mono">
              {{ newFolderParent ? getFolderName(newFolderParent) + ' / ' : '' }}{{ newFolderName.trim() }}
            </p>
          </div>
          
          <div class="flex justify-end gap-2">
            <button @click="showCreateFolder = false; newFolderName = ''; newFolderParent = ''" class="btn-ghost">Cancel</button>
            <button 
              @click="createNewFolder(createTargetAction)" 
              :disabled="creatingFolder || !newFolderName.trim()"
              class="btn-primary"
            >
              <span v-if="creatingFolder" class="spinner"></span>
              Create
            </button>
          </div>
        </div>
      </div>
    </Transition>
    
    <!-- Create Label Modal -->
    <Transition name="fade">
      <div v-if="showCreateLabel" class="fixed inset-0 z-[110] flex items-center justify-center p-4 bg-black/50" @mousedown.self="showCreateLabel = false">
        <div class="w-full max-w-sm bg-white dark:bg-surface-800 rounded-xl shadow-2xl p-6">
          <h4 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4">Create New Label</h4>
          <input
            v-model="newLabelName"
            type="text"
            class="input w-full mb-3"
            placeholder="Label name..."
            @keyup.enter="createNewLabel(createTargetAction)"
            autofocus
          />
          <div class="mb-4">
            <p class="text-sm text-surface-500 mb-2">Color</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="color in colorOptions"
                :key="color.name"
                @click="newLabelColor = color.hex"
                class="w-6 h-6 rounded-full transition-transform hover:scale-110"
                :class="{ 'ring-2 ring-offset-2 ring-surface-900 dark:ring-white': newLabelColor === color.hex }"
                :style="{ backgroundColor: color.hex }"
                :title="color.name"
              ></button>
            </div>
          </div>
          <div class="flex justify-end gap-2">
            <button @click="showCreateLabel = false; newLabelName = ''" class="btn-ghost">Cancel</button>
            <button 
              @click="createNewLabel(createTargetAction)" 
              :disabled="creatingLabel || !newLabelName.trim()"
              class="btn-primary"
            >
              <span v-if="creatingLabel" class="spinner"></span>
              Create
            </button>
          </div>
        </div>
      </div>
    </Transition>
    
    <!-- Run Filter Modal -->
    <Transition name="fade">
      <div v-if="showRunFilterModal" class="fixed inset-0 z-[110] flex items-center justify-center p-4 bg-black/50" @mousedown.self="!runFilterRunning && closeRunFilterModal()">
        <div class="w-full max-w-lg bg-white dark:bg-surface-800 rounded-2xl shadow-2xl overflow-hidden">
          <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100">
              Run Filter: {{ runFilterTarget?.name }}
            </h3>
            <button @click="closeRunFilterModal" class="btn-ghost btn-icon" :disabled="runFilterRunning">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div class="p-6 space-y-4">
            <!-- Settings (before running) -->
            <div v-if="!runFilterResults && !runFilterRunning" class="space-y-4">
              <!-- Folder scope toggle -->
              <div class="flex rounded-xl overflow-hidden border border-surface-200 dark:border-surface-700">
                <button
                  @click="runFilterAllFolders = false"
                  :class="[
                    'flex-1 flex items-center justify-center gap-2 py-2.5 text-sm font-medium transition-colors',
                    !runFilterAllFolders 
                      ? 'bg-primary-500 text-white' 
                      : 'bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">folder</span>
                  Single Folder
                </button>
                <button
                  @click="runFilterAllFolders = true"
                  :class="[
                    'flex-1 flex items-center justify-center gap-2 py-2.5 text-sm font-medium transition-colors',
                    runFilterAllFolders 
                      ? 'bg-primary-500 text-white' 
                      : 'bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400 hover:bg-surface-200 dark:hover:bg-surface-700'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">folder_copy</span>
                  All Folders
                </button>
              </div>
              
              <!-- Folder selector (only for single folder mode) -->
              <div v-if="!runFilterAllFolders">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  Select folder
                </label>
                <select v-model="runFilterFolder" class="select-input w-full">
                  <option v-for="folder in filteredFolders" :key="folder.name" :value="folder.name">
                    {{ getFolderName(folder.name) }}
                  </option>
                </select>
              </div>
              
              <div class="bg-surface-100 dark:bg-surface-700 rounded-lg p-3">
                <p class="text-sm text-surface-600 dark:text-surface-400">
                  This will apply "<span class="font-medium">{{ runFilterTarget?.name }}</span>" to all messages in 
                  <span class="font-medium">{{ runFilterAllFolders ? 'all folders' : getFolderName(runFilterFolder) }}</span>
                </p>
              </div>
            </div>
            
            <!-- Running with progress -->
            <div v-if="runFilterRunning" class="flex flex-col items-center py-6">
              <span class="spinner text-primary-500 w-10 h-10 mb-4"></span>
              <p class="text-surface-900 dark:text-surface-100 font-medium mb-2">
                Processing...
              </p>
              <div class="text-sm text-surface-500 text-center space-y-1">
                <p v-if="runFilterAllFolders" class="text-xs text-primary-400 mb-1">Running on all folders</p>
                <p>Page {{ runFilterProgress.currentPage }} of {{ runFilterProgress.totalPages }}</p>
                <p>{{ runFilterProgress.totalProcessed }} messages scanned total</p>
                <p class="text-primary-500 font-medium">{{ runFilterProgress.totalMatched }} matched so far</p>
                <p v-if="runFilterProgress.allActions.length > 0" class="text-green-500">
                  {{ runFilterProgress.allActions.length }} action(s) applied
                </p>
              </div>
              <!-- Progress bar -->
              <div class="w-full max-w-xs mt-4 bg-surface-200 dark:bg-surface-700 rounded-full h-2">
                <div 
                  class="bg-primary-500 h-2 rounded-full transition-all duration-300"
                  :style="{ width: `${Math.min(100, (runFilterProgress.currentPage / Math.max(1, runFilterProgress.totalPages)) * 100)}%` }"
                ></div>
              </div>
              <p class="text-xs text-surface-400 mt-2">
                Batch {{ runFilterProgress.batchesCompleted }} completed
              </p>
            </div>
            
            <!-- Results -->
            <div v-if="runFilterResults && !runFilterRunning" class="space-y-3">
              <div class="text-center mb-4">
                <span :class="['material-symbols-rounded text-4xl mb-2', runFilterResults.cancelled ? 'text-amber-500' : (runFilterResults.actions.length > 0 ? 'text-primary-500' : 'text-surface-400')]">
                  {{ runFilterResults.cancelled ? 'cancel' : (runFilterResults.actions.length > 0 ? 'check_circle' : 'info') }}
                </span>
                <p class="text-lg font-medium text-surface-900 dark:text-surface-100">
                  {{ runFilterResults.cancelled ? 'Cancelled' : 'Complete' }} — {{ runFilterResults.matched }} messages matched
                </p>
                <p class="text-sm text-surface-500 mt-1">
                  Scanned {{ runFilterResults.scanned }} messages
                  <span v-if="runFilterResults.batchesCompleted > 1">({{ runFilterResults.batchesCompleted }} batches)</span>
                </p>
              </div>
              
              <div v-if="runFilterResults.actions.length > 0" class="max-h-48 overflow-y-auto bg-surface-100 dark:bg-surface-700 rounded-lg p-3 text-sm">
                <div v-for="(action, i) in runFilterResults.actions.slice(0, 15)" :key="i" class="py-1 flex items-center gap-2">
                  <span class="material-symbols-rounded text-primary-500 text-base">check</span>
                  <span class="text-surface-700 dark:text-surface-300">{{ action.action }}</span>
                  <span class="text-surface-500 truncate">"{{ action.subject?.substring(0, 30) }}{{ action.subject?.length > 30 ? '...' : '' }}"</span>
                </div>
                <p v-if="runFilterResults.actions.length > 15" class="text-xs text-surface-500 mt-2">
                  + {{ runFilterResults.actions.length - 15 }} more actions
                </p>
              </div>
              
              <p v-else class="text-center text-surface-500 py-4">
                No messages matched this filter's conditions
              </p>
            </div>
          </div>
          
          <div class="px-6 py-4 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-2">
            <!-- Cancel button while running -->
            <button v-if="runFilterRunning" @click="cancelRunFilter" class="btn-secondary text-red-500 border-red-300 hover:bg-red-50 dark:hover:bg-red-900/20">
              <span class="material-symbols-rounded">stop</span>
              Cancel
            </button>
            
            <button v-if="!runFilterResults && !runFilterRunning" @click="closeRunFilterModal" class="btn-ghost">
              Cancel
            </button>
            <button v-if="!runFilterResults && !runFilterRunning" @click="executeRunFilter" class="btn-primary">
              <span class="material-symbols-rounded">play_arrow</span>
              Run Filter
            </button>
            
            <!-- Run again after results -->
            <button v-if="runFilterResults && !runFilterRunning" @click="runFilterResults = null" class="btn-secondary">
              <span class="material-symbols-rounded">refresh</span>
              New Run
            </button>
            <button v-if="runFilterResults && !runFilterRunning" @click="closeRunFilterModal" class="btn-primary">
              Done
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
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

.select-input.select-error {
  border-color: rgb(248 113 113) !important;
  background-color: rgb(254 242 242) !important;
}

:deep(.dark) .select-input.select-error,
.dark .select-input.select-error {
  border-color: rgb(220 38 38) !important;
  background-color: rgba(127, 29, 29, 0.2) !important;
}

/* Backdrop blur transition */
.backdrop-blur-enter-active,
.backdrop-blur-leave-active {
  transition: opacity 0.2s ease;
}
.backdrop-blur-enter-from,
.backdrop-blur-leave-to {
  opacity: 0;
}

/* Modal slide transition */
.modal-slide-enter-active,
.modal-slide-leave-active {
  transition: all 0.25s ease;
}
.modal-slide-enter-from {
  opacity: 0;
  transform: translateY(-20px) scale(0.95);
}
.modal-slide-leave-to {
  opacity: 0;
  transform: translateY(-10px) scale(0.98);
}

/* Fade transition for sub-modals */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
