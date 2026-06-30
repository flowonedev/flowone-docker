<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import Toggle from '@/components/Toggle.vue'
import BackupLocationBadge from '@/components/BackupLocationBadge.vue'

const toast = useToastStore()

const activeTab = ref('backups')
const loading = ref(true)
const submitting = ref(false)

// Backups state
const backups = ref([])
const pagination = ref({ total: 0, page: 1, per_page: 20, total_pages: 1 })
const selectedBackups = ref([])
const deletingBackups = ref(false)
const deleteMultipleModal = ref({ show: false })

// Site backups state
const siteBackups = ref([])
const siteBackupsLoading = ref(false)
const sites = ref([])
const sitesLoading = ref(false)

// Email backups state
const mailBackups = ref([])
const mailBackupsLoading = ref(false)
const mailDomains = ref([])
const mailDomainsLoading = ref(false)
const mailBackupModal = ref({ show: false, domain: null, destination: 'local' })
const restoreMailModal = ref({
  show: false,
  backup: null,
  loading: false,
  contents: null,
  restoreMailboxes: true,
  restoreAccounts: true,
  restoreDkim: true,
  mode: 'merge',
  // Dry run state
  dryRunning: false,
  dryRunLogs: null,
  dryRunAnalysis: null,
})

// Schedules state
const schedules = ref([])
const schedulesLoading = ref(false)
const cronDaemon = ref(null) // health info from the agent (null until loaded)
const repairingCron = ref(false)
const runningScheduleId = ref(null) // schedule currently being triggered via Run Now

// Manual transfer-to-NAS state
const transferModal = ref({ show: false, backup: null, mode: 'copy' })
const transferring = ref(false)

// Categories state
const categories = ref([])
const categoriesLoading = ref(false)

// Modals
const createBackupModal = ref(false)
const createScheduleModal = ref(false)
const editScheduleModal = ref({ show: false, schedule: null })
const cleanupModal = ref({ show: false, days: 30 })
const deleteModal = ref({ show: false, backup: null })
const restoreModal = ref({ show: false, backup: null })
const deleteScheduleModal = ref({ show: false, schedule: null })
const siteBackupModal = ref({ show: false, domain: null, destination: 'local' })

// Enhanced restore modals with selection
const restoreSiteModal = ref({ 
  show: false, 
  backup: null,
  loading: false,
  contents: null, // Loaded from inspection API
  // Selection state
  restoreFiles: true,
  fileComponents: [], // ['plugins', 'themes', 'uploads', 'wpcore'] or empty for all
  restoreDatabase: true,
  selectedDatabases: [], // Database names to restore, empty = all
  restoreVhost: false,
  restoreSsl: false,
  restoreDns: false,
  restoreMail: false,
  mode: 'merge', // 'merge' (safe) or 'replace' (destructive)
  // Dry run state
  dryRunning: false,
  dryRunLogs: null, // Array of log entries from dry run
  dryRunAnalysis: null, // Analysis data from dry run
})

const restoreConfigModal = ref({
  show: false,
  backup: null,
  loading: false,
  contents: null, // Loaded from inspection API
  selectedCategories: [], // Category IDs to restore
  // Dry run state
  dryRunning: false,
  dryRunLogs: null, // Array of log entries from dry run
})

// NAS state
const nasConnections = ref([])
const nasLoading = ref(false)
const selectedNasId = ref(null)
const remoteBackups = ref([])
const remoteBackupsLoading = ref(false)
const selectedNasBackups = ref([])
const deletingNasBackups = ref(false)
const deleteNasModal = ref({ show: false })
const deleteNasConfirmText = ref('')
const activeNasTab = ref('config') // 'config', 'sites', 'emails'

// Backup Progress state (for async backups with progress tracking)
const backupProgress = ref({
  show: false,
  domain: null,
  statusId: null,
  status: 'running', // running, completed, failed, stale
  step: 'initializing', // initializing, files, database, config, archiving, uploading
  progress: 0,
  message: 'Starting backup...',
  startedAt: null,
  result: null, // filled when completed
})

// Email Backup Progress state (separate from site backups)
const mailBackupProgress = ref({
  show: false,
  domain: null,
  statusId: null,
  status: 'running', // running, completed, failed
  step: 'initializing', // initializing, mailboxes, accounts, dkim, archiving, uploading
  progress: 0,
  message: 'Starting mail backup...',
  startedAt: null,
  result: null,
})
let backupProgressInterval = null
let mailBackupProgressInterval = null

// Backup progress step definitions for UI
const backupSteps = [
  { id: 'files', label: 'Files', icon: 'folder' },
  { id: 'database', label: 'Database', icon: 'database' },
  { id: 'config', label: 'Config', icon: 'settings' },
  { id: 'archiving', label: 'Archive', icon: 'inventory_2' },
  { id: 'uploading', label: 'Upload', icon: 'cloud_upload' },
]

// Email backup progress step definitions
const mailBackupSteps = [
  { id: 'mailboxes', label: 'Mailboxes', icon: 'inbox' },
  { id: 'accounts', label: 'Accounts', icon: 'person' },
  { id: 'dkim', label: 'DKIM', icon: 'key' },
  { id: 'archiving', label: 'Archive', icon: 'inventory_2' },
  { id: 'uploading', label: 'Upload', icon: 'cloud_upload' },
]

// Forms
const selectedCategories = ref([])
const backupDestination = ref('local') // 'local', 'nas', 'both'
const newSchedule = ref({
  type: 'config', // 'config' or 'site'
  frequency: 'daily',
  time: '03:00',
  day_of_week: 0, // 0=Sunday .. 6=Saturday (weekly frequency only)
  categories: [],
  retention: 7,
  destination: 'local', // 'local', 'nas', 'both'
  // Site backup options
  sites: [], // selected site domains or 'all'
  allSites: false,
  components: ['all'], // all, database, plugins, wpcore, uploads, themes
})

// Edit schedule form (separate from create to avoid conflicts)
const editSchedule = ref({
  id: null,
  type: 'config',
  frequency: 'daily',
  time: '03:00',
  day_of_week: 0,
  categories: [],
  retention: 7,
  destination: 'local',
  sites: [],
  allSites: false,
  components: ['all'],
  enabled: true,
})

// Weekday options for weekly schedules
const dayOfWeekOptions = [
  { value: 1, label: 'Monday' },
  { value: 2, label: 'Tuesday' },
  { value: 3, label: 'Wednesday' },
  { value: 4, label: 'Thursday' },
  { value: 5, label: 'Friday' },
  { value: 6, label: 'Saturday' },
  { value: 0, label: 'Sunday' },
]

// Site backup component options
const siteBackupComponents = [
  { id: 'all', label: 'Everything', description: 'Files + Database + All components', icon: 'select_all' },
  { id: 'database', label: 'Database Only', description: 'MySQL database dump', icon: 'database' },
  { id: 'plugins', label: 'Plugins Only', description: 'wp-content/plugins folder', icon: 'extension' },
  { id: 'wpcore', label: 'WP Core Only', description: 'WordPress core files (excludes wp-content)', icon: 'code' },
  { id: 'uploads', label: 'Uploads Only', description: 'wp-content/uploads folder', icon: 'image' },
  { id: 'themes', label: 'Themes Only', description: 'wp-content/themes folder', icon: 'palette' },
  { id: 'ssl', label: 'SSL Certificates', description: 'Let\'s Encrypt certificates', icon: 'lock' },
  { id: 'vhost', label: 'vHost Config', description: 'OpenLiteSpeed virtual host config', icon: 'dns' },
]

// Toggle category selection helpers
const toggleCategory = (categoryId) => {
  const idx = selectedCategories.value.indexOf(categoryId)
  if (idx === -1) {
    selectedCategories.value.push(categoryId)
  } else {
    selectedCategories.value.splice(idx, 1)
  }
}

const toggleScheduleCategory = (categoryId) => {
  const idx = newSchedule.value.categories.indexOf(categoryId)
  if (idx === -1) {
    newSchedule.value.categories.push(categoryId)
  } else {
    newSchedule.value.categories.splice(idx, 1)
  }
}

const toggleScheduleSite = (domain) => {
  const idx = newSchedule.value.sites.indexOf(domain)
  if (idx === -1) {
    newSchedule.value.sites.push(domain)
  } else {
    newSchedule.value.sites.splice(idx, 1)
  }
}

const toggleScheduleComponent = (componentId) => {
  // If selecting 'all', clear others
  if (componentId === 'all') {
    newSchedule.value.components = ['all']
    return
  }
  
  // If selecting a specific component, remove 'all'
  const allIdx = newSchedule.value.components.indexOf('all')
  if (allIdx !== -1) {
    newSchedule.value.components.splice(allIdx, 1)
  }
  
  const idx = newSchedule.value.components.indexOf(componentId)
  if (idx === -1) {
    newSchedule.value.components.push(componentId)
  } else {
    newSchedule.value.components.splice(idx, 1)
  }
  
  // If no components selected, default to 'all'
  if (newSchedule.value.components.length === 0) {
    newSchedule.value.components = ['all']
  }
}

const isCategorySelected = (categoryId) => selectedCategories.value.includes(categoryId)
const isScheduleCategorySelected = (categoryId) => newSchedule.value.categories.includes(categoryId)
const isScheduleSiteSelected = (domain) => newSchedule.value.sites.includes(domain)
const isScheduleComponentSelected = (componentId) => newSchedule.value.components.includes(componentId)

// Edit schedule toggle functions
const toggleEditScheduleCategory = (categoryId) => {
  const idx = editSchedule.value.categories.indexOf(categoryId)
  if (idx === -1) {
    editSchedule.value.categories.push(categoryId)
  } else {
    editSchedule.value.categories.splice(idx, 1)
  }
}

const toggleEditScheduleSite = (domain) => {
  const idx = editSchedule.value.sites.indexOf(domain)
  if (idx === -1) {
    editSchedule.value.sites.push(domain)
  } else {
    editSchedule.value.sites.splice(idx, 1)
  }
}

const toggleEditScheduleComponent = (componentId) => {
  if (componentId === 'all') {
    editSchedule.value.components = ['all']
    return
  }
  
  const allIdx = editSchedule.value.components.indexOf('all')
  if (allIdx !== -1) {
    editSchedule.value.components.splice(allIdx, 1)
  }
  
  const idx = editSchedule.value.components.indexOf(componentId)
  if (idx === -1) {
    editSchedule.value.components.push(componentId)
  } else {
    editSchedule.value.components.splice(idx, 1)
  }
  
  if (editSchedule.value.components.length === 0) {
    editSchedule.value.components = ['all']
  }
}

const isEditScheduleCategorySelected = (categoryId) => editSchedule.value.categories.includes(categoryId)
const isEditScheduleSiteSelected = (domain) => editSchedule.value.sites.includes(domain)
const isEditScheduleComponentSelected = (componentId) => editSchedule.value.components.includes(componentId)

// Check if all available categories are selected
const allCategoriesSelected = computed(() => {
  const availableCategories = categories.value.filter(c => c.exists).map(c => c.id)
  return availableCategories.length > 0 && availableCategories.every(id => selectedCategories.value.includes(id))
})

// Toggle all categories
const toggleAllCategories = () => {
  const availableCategories = categories.value.filter(c => c.exists).map(c => c.id)
  if (allCategoriesSelected.value) {
    // Deselect all
    selectedCategories.value = []
  } else {
    // Select all available
    selectedCategories.value = [...availableCategories]
  }
}

// ============================================
// Backups Functions
// ============================================
const fetchBackups = async (page = 1, forceRefresh = false) => {
  loading.value = true
  selectedBackups.value = [] // Clear selection on page change
  try {
    const params = { page, per_page: 20 }
    if (forceRefresh) {
      params.refresh = '1'
    }
    const response = await api.get('/backups', { params })
    if (response.data.success) {
      backups.value = response.data.data.backups || []
      pagination.value = response.data.data.pagination
    }
  } catch (e) {
    toast.error('Failed to load backups')
  } finally {
    loading.value = false
  }
}

// Local backup selection
const toggleBackupSelection = (id) => {
  const idx = selectedBackups.value.indexOf(id)
  if (idx === -1) {
    selectedBackups.value.push(id)
  } else {
    selectedBackups.value.splice(idx, 1)
  }
}

const toggleAllBackups = () => {
  if (selectedBackups.value.length === backups.value.length) {
    selectedBackups.value = []
  } else {
    selectedBackups.value = backups.value.map(b => b.id)
  }
}

const allBackupsSelected = computed(() => {
  return backups.value.length > 0 && selectedBackups.value.length === backups.value.length
})

const deleteSelectedBackups = async () => {
  if (selectedBackups.value.length === 0) return
  
  deletingBackups.value = true
  let deleted = 0
  let errors = 0
  
  try {
    // Delete each selected backup
    for (const id of selectedBackups.value) {
      try {
        const response = await api.delete(`/backups/${id}`)
        if (response.data.success) {
          deleted++
        } else {
          errors++
        }
      } catch {
        errors++
      }
    }
    
    if (deleted > 0) {
      toast.success(`${deleted} backup(s) deleted`)
    }
    if (errors > 0) {
      toast.warning(`${errors} backup(s) could not be deleted`)
    }
    
    selectedBackups.value = []
    deleteMultipleModal.value.show = false
    await fetchBackups(pagination.value.page, true) // Force refresh after delete
  } catch (e) {
    toast.error('Failed to delete backups')
  } finally {
    deletingBackups.value = false
  }
}

const fetchCategories = async () => {
  categoriesLoading.value = true
  try {
    const response = await api.get('/backups/categories')
    if (response.data.success) {
      categories.value = response.data.data.categories || []
    }
  } catch (e) {
    toast.error('Failed to load backup categories')
  } finally {
    categoriesLoading.value = false
  }
}

const createBackup = async () => {
  if (selectedCategories.value.length === 0) {
    toast.error('Please select at least one category')
    return
  }

  // Check NAS config if destination requires it
  if ((backupDestination.value === 'nas' || backupDestination.value === 'both') && nasConnections.value.length === 0) {
    toast.error('No NAS connections available. Please configure NAS in NAS Storage page first.')
    return
  }

  submitting.value = true
  try {
    const response = await api.post('/backups/create', {
      categories: selectedCategories.value,
      destination: backupDestination.value
    })
    if (response.data.success) {
      const destLabel = backupDestination.value === 'both' ? 'locally and to NAS' : 
                        backupDestination.value === 'nas' ? 'to NAS' : 'locally'
      toast.success(`Backup created ${destLabel}`)
      createBackupModal.value = false
      selectedCategories.value = []
      backupDestination.value = 'local'
      await fetchBackups(1)
      if (backupDestination.value !== 'local') {
        await fetchRemoteBackups()
      }
    } else {
      toast.error(response.data.error || 'Failed to create backup')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create backup')
  } finally {
    submitting.value = false
  }
}

// Open config restore modal and load inspection data
const openConfigRestoreModal = async (backup) => {
  restoreConfigModal.value = {
    show: true,
    backup,
    loading: true,
    contents: null,
    selectedCategories: [],
  }
  
  try {
    const response = await api.get(`/backups/inspect/config/${backup.id}`)
    if (response.data.success) {
      restoreConfigModal.value.contents = response.data.data
      // Pre-select all categories
      restoreConfigModal.value.selectedCategories = response.data.data.categories?.map(c => c.id) || []
    } else {
      toast.error(response.data.error || 'Failed to inspect backup')
      restoreConfigModal.value.show = false
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to inspect backup')
    restoreConfigModal.value.show = false
  } finally {
    restoreConfigModal.value.loading = false
  }
}

// Dry run config restore to preview changes
const dryRunConfigRestore = async () => {
  if (!restoreConfigModal.value.backup || restoreConfigModal.value.selectedCategories.length === 0) return
  
  restoreConfigModal.value.dryRunning = true
  restoreConfigModal.value.dryRunLogs = null
  
  try {
    const response = await api.post(`/backups/restore/config/${restoreConfigModal.value.backup.id}`, {
      categories: restoreConfigModal.value.selectedCategories,
      dry_run: true,
    })
    
    if (response.data.success) {
      restoreConfigModal.value.dryRunLogs = response.data.data?.logs || []
      toast.success('Dry run complete - review the results below')
    } else {
      toast.error(response.data.error || 'Dry run failed')
      restoreConfigModal.value.dryRunLogs = response.data.logs || []
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Dry run failed')
  } finally {
    restoreConfigModal.value.dryRunning = false
  }
}

// Restore config backup with selected categories
const restoreConfigBackup = async () => {
  if (!restoreConfigModal.value.backup || restoreConfigModal.value.selectedCategories.length === 0) return
  
  submitting.value = true
  try {
    const response = await api.post(`/backups/restore/config/${restoreConfigModal.value.backup.id}`, {
      categories: restoreConfigModal.value.selectedCategories,
    })
    
    if (response.data.success) {
      const data = response.data.data
      const restored = data?.restored?.length || 0
      const errors = data?.errors?.length || 0
      
      if (errors > 0) {
        toast.warning(`${restored} items restored, ${errors} errors`)
      } else {
        toast.success(`${restored} items restored successfully`)
      }
      
      restoreConfigModal.value = { show: false, backup: null, loading: false, contents: null, selectedCategories: [], dryRunning: false, dryRunLogs: null }
    } else {
      toast.error(response.data.error || 'Failed to restore')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restore')
  } finally {
    submitting.value = false
  }
}

// Toggle category selection for config restore
const toggleConfigCategory = (categoryId) => {
  const idx = restoreConfigModal.value.selectedCategories.indexOf(categoryId)
  if (idx === -1) {
    restoreConfigModal.value.selectedCategories.push(categoryId)
  } else {
    restoreConfigModal.value.selectedCategories.splice(idx, 1)
  }
}

// Legacy simple restore (for .bak files)
const restoreBackup = async () => {
  if (!restoreModal.value.backup) return
  
  submitting.value = true
  try {
    const response = await api.post(`/backups/${restoreModal.value.backup.id}/restore`)
    if (response.data.success) {
      const data = response.data.data
      const restored = data?.restored?.length || 0
      const errors = data?.errors?.length || 0
      
      if (errors > 0) {
        toast.warning(`${restored} items restored, ${errors} errors`)
      } else {
        toast.success(`${restored} items restored successfully`)
      }
      
      restoreModal.value = { show: false, backup: null }
    } else {
      toast.error(response.data.error || 'Failed to restore')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restore')
  } finally {
    submitting.value = false
  }
}

const getRestoreMessage = (backup) => {
  if (!backup) return ''
  
  if (backup.type === 'archive' || backup.filename?.endsWith('.tar.gz')) {
    const contents = backup.contents?.length 
      ? `\n\nContents: ${backup.contents.join(', ')}`
      : ''
    return `This will restore ALL configurations from this backup archive to their original locations. Current files will be backed up to pre-restore folder before overwriting.${contents}`
  }
  
  return `Restore this backup to ${backup.original_path}? This will overwrite the current file.`
}

// Decide which restore modal to open based on backup type
const openRestoreModal = (backup) => {
  // For config backups (tar.gz archives), use the new selective modal
  if (backup.type === 'archive' || backup.filename?.endsWith('.tar.gz')) {
    openConfigRestoreModal(backup)
  } else {
    // For simple .bak files, use the old modal
    restoreModal.value = { show: true, backup }
  }
}

const deleteBackup = async () => {
  if (!deleteModal.value.backup) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/backups/${deleteModal.value.backup.id}`)
    if (response.data.success) {
      toast.success('Backup deleted')
      deleteModal.value = { show: false, backup: null }
      
      // Refresh the correct list based on active tab
      if (activeTab.value === 'sites') {
        await fetchSiteBackups()
      } else {
        await fetchBackups(pagination.value.page, true)
      }
    } else {
      toast.error(response.data.error || 'Failed to delete')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete')
  } finally {
    submitting.value = false
  }
}

const downloadBackup = async (backup) => {
  try {
    toast.info('Preparing download...')
    const response = await api.get(`/backups/${backup.id}/download`, {
      responseType: 'blob'
    })
    
    // Create download link
    const url = window.URL.createObjectURL(new Blob([response.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', backup.filename)
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
    
    toast.success('Download started')
  } catch (e) {
    toast.error('Failed to download backup')
    console.error(e)
  }
}

const runCleanup = async () => {
  submitting.value = true
  try {
    const response = await api.post('/backups/cleanup', {
      max_age_days: cleanupModal.value.days
    })
    if (response.data.success) {
      toast.success(`${response.data.data.count} backups deleted`)
      cleanupModal.value.show = false
      await fetchBackups(1, true) // Force refresh after cleanup
    } else {
      toast.error(response.data.error || 'Cleanup failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Cleanup failed')
  } finally {
    submitting.value = false
  }
}

// ============================================
// Schedules Functions
// ============================================
const fetchSchedules = async () => {
  schedulesLoading.value = true
  try {
    // Always bypass the server cache: last-run status and cron health
    // change between visits and a stale view here is exactly the
    // "backups silently not running" problem we are fixing.
    const response = await api.get('/backups/schedules', { params: { refresh: '1' } })
    if (response.data.success) {
      schedules.value = response.data.data.schedules || []
      cronDaemon.value = response.data.data.cron_daemon || null
    }
  } catch (e) {
    toast.error('Failed to load schedules')
  } finally {
    schedulesLoading.value = false
  }
}

const runScheduleNow = async (schedule) => {
  runningScheduleId.value = schedule.id
  try {
    const response = await api.post(`/backups/schedules/${schedule.id}/run`)
    if (response.data.success) {
      toast.success('Backup started in the background - refresh in a bit to see the result')
      await fetchSchedules()
    } else {
      toast.error(response.data.error || 'Failed to start backup')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to start backup')
  } finally {
    runningScheduleId.value = null
  }
}

const repairCron = async () => {
  repairingCron.value = true
  try {
    const response = await api.post('/backups/cron/repair', {}, { timeout: 400000 })
    if (response.data.success) {
      toast.success('Cron daemon is installed, enabled and running')
    } else {
      toast.error(response.data.error || 'Cron repair failed')
    }
    await fetchSchedules()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Cron repair failed')
  } finally {
    repairingCron.value = false
  }
}

const createSchedule = async () => {
  // Validation based on type
  if (newSchedule.value.type === 'config') {
    if (newSchedule.value.categories.length === 0) {
      toast.error('Please select at least one category')
      return
    }
  } else if (newSchedule.value.type === 'site') {
    if (!newSchedule.value.allSites && newSchedule.value.sites.length === 0) {
      toast.error('Please select at least one site or "All Sites"')
      return
    }
    if (newSchedule.value.components.length === 0) {
      toast.error('Please select at least one component to backup')
      return
    }
  }

  // Check NAS config if destination requires it
  if ((newSchedule.value.destination === 'nas' || newSchedule.value.destination === 'both') && nasConnections.value.length === 0) {
    toast.error('No NAS connections available. Please configure NAS in NAS Storage page first.')
    return
  }

  submitting.value = true
  try {
    const payload = {
      type: newSchedule.value.type,
      frequency: newSchedule.value.frequency,
      time: newSchedule.value.time,
      day_of_week: newSchedule.value.frequency === 'weekly' ? newSchedule.value.day_of_week : 0,
      retention: newSchedule.value.retention,
      destination: newSchedule.value.destination,
    }
    
    if (newSchedule.value.type === 'config') {
      payload.categories = newSchedule.value.categories
    } else {
      payload.sites = newSchedule.value.allSites ? 'all' : newSchedule.value.sites
      payload.components = newSchedule.value.components
    }
    
    const response = await api.post('/backups/schedules', payload)
    if (response.data.success) {
      toast.success('Schedule created successfully')
      createScheduleModal.value = false
      resetNewSchedule()
      await fetchSchedules()
    } else {
      toast.error(response.data.error || 'Failed to create schedule')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create schedule')
  } finally {
    submitting.value = false
  }
}

const toggleSchedule = async (schedule) => {
  submitting.value = true
  try {
    const response = await api.put(`/backups/schedules/${schedule.id}`, {
      enabled: !schedule.enabled
    })
    if (response.data.success) {
      toast.success(`Schedule ${schedule.enabled ? 'disabled' : 'enabled'}`)
      await fetchSchedules()
    } else {
      toast.error(response.data.error || 'Failed to update schedule')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update schedule')
  } finally {
    submitting.value = false
  }
}

const deleteSchedule = async () => {
  if (!deleteScheduleModal.value.schedule) return

  submitting.value = true
  try {
    const response = await api.delete(`/backups/schedules/${deleteScheduleModal.value.schedule.id}`)
    if (response.data.success) {
      toast.success('Schedule deleted')
      deleteScheduleModal.value = { show: false, schedule: null }
      await fetchSchedules()
    } else {
      toast.error(response.data.error || 'Failed to delete schedule')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete schedule')
  } finally {
    submitting.value = false
  }
}

const openEditScheduleModal = async (schedule) => {
  // Load categories and sites if needed
  const promises = []
  if (categories.value.length === 0) {
    promises.push(fetchCategories())
  }
  if (schedule.type === 'site' && sites.value.length === 0) {
    promises.push(fetchSites())
  }
  if (promises.length > 0) {
    await Promise.all(promises)
  }
  
  // Populate the edit form with schedule data
  editSchedule.value = {
    id: schedule.id,
    type: schedule.type || 'config',
    frequency: schedule.frequency || 'daily',
    time: schedule.time || '03:00',
    day_of_week: schedule.day_of_week ?? 0,
    categories: schedule.categories || [],
    retention: schedule.retention || 7,
    destination: schedule.destination || 'local',
    sites: schedule.sites === 'all' ? [] : (schedule.sites || []),
    allSites: schedule.sites === 'all',
    components: schedule.components || ['all'],
    enabled: schedule.enabled !== false,
  }
  
  editScheduleModal.value = { show: true, schedule }
}

const updateSchedule = async () => {
  // Validation based on type
  if (editSchedule.value.type === 'config') {
    if (editSchedule.value.categories.length === 0) {
      toast.error('Please select at least one category')
      return
    }
  } else if (editSchedule.value.type === 'site') {
    if (!editSchedule.value.allSites && editSchedule.value.sites.length === 0) {
      toast.error('Please select at least one site or "All Sites"')
      return
    }
    if (editSchedule.value.components.length === 0) {
      toast.error('Please select at least one component to backup')
      return
    }
  }

  // Check NAS config if destination requires it
  if ((editSchedule.value.destination === 'nas' || editSchedule.value.destination === 'both') && nasConnections.value.length === 0) {
    toast.error('No NAS connections available. Please configure NAS in NAS Storage page first.')
    return
  }

  submitting.value = true
  try {
    const payload = {
      type: editSchedule.value.type,
      frequency: editSchedule.value.frequency,
      time: editSchedule.value.time,
      day_of_week: editSchedule.value.frequency === 'weekly' ? editSchedule.value.day_of_week : 0,
      retention: editSchedule.value.retention,
      destination: editSchedule.value.destination,
      enabled: editSchedule.value.enabled,
    }
    
    if (editSchedule.value.type === 'config') {
      payload.categories = editSchedule.value.categories
    } else {
      payload.sites = editSchedule.value.allSites ? 'all' : editSchedule.value.sites
      payload.components = editSchedule.value.components
    }

    const response = await api.put(`/backups/schedules/${editSchedule.value.id}`, payload)
    if (response.data.success) {
      toast.success('Schedule updated successfully')
      editScheduleModal.value = { show: false, schedule: null }
      await fetchSchedules()
    } else {
      toast.error(response.data.error || 'Failed to update schedule')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update schedule')
  } finally {
    submitting.value = false
  }
}

const resetNewSchedule = () => {
  newSchedule.value = {
    type: 'config',
    frequency: 'daily',
    time: '03:00',
    day_of_week: 0,
    categories: [],
    retention: 7,
    destination: 'local',
    sites: [],
    allSites: false,
    components: ['all'],
  }
}

// ============================================
// Manual transfer to NAS (copy or move)
// ============================================
const openTransferModal = (backup) => {
  if (nasConnections.value.length === 0) {
    // Connections may simply not be loaded yet on this tab
    fetchNasConnections()
  }
  transferModal.value = { show: true, backup, mode: 'copy' }
}

const transferToNas = async () => {
  const backup = transferModal.value.backup
  if (!backup) return

  const mode = transferModal.value.mode
  transferring.value = true
  try {
    // Async: the agent validates, spawns a detached runner and returns a
    // status_id immediately. The panel stays fully usable; we poll in the
    // background and toast when the transfer finishes.
    const response = await api.post('/backups/transfer/nas', {
      id: backup.id,
      mode,
      async: true,
    })

    if (response.data.success && response.data.data?.status_id) {
      transferModal.value = { show: false, backup: null, mode: 'copy' }
      const fileName = backup.filename || backup.name || 'backup'
      toast.info(`Transfer to NAS started in the background: ${fileName}`)
      startTransferPoll(response.data.data.status_id, backup, mode)
    } else if (response.data.success) {
      // Sync response (older agent): transfer already finished
      toast.success(response.data.message || 'Backup transferred to NAS')
      transferModal.value = { show: false, backup: null, mode: 'copy' }
      await refreshAfterTransfer(backup)
    } else {
      toast.error(response.data.error || 'Transfer failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Transfer failed')
  } finally {
    transferring.value = false
  }
}

// Poll a background NAS transfer until it finishes (panel stays usable)
const activeTransferPolls = new Set()

const startTransferPoll = (statusId, backup, mode) => {
  const interval = setInterval(async () => {
    try {
      const res = await api.get('/backups/status', { params: { status_id: statusId } })
      if (!res.data.success) return

      const data = res.data.data
      if (data.status === 'running') return

      clearInterval(interval)
      activeTransferPolls.delete(interval)

      if (data.status === 'completed') {
        toast.success(mode === 'move'
          ? 'Backup moved to NAS (server copy freed)'
          : 'Backup copied to NAS')
        await refreshAfterTransfer(backup)
      } else {
        toast.error(data.result?.error || data.message || 'Transfer to NAS failed')
      }
    } catch (e) {
      // Transient poll errors are ignored; the next tick retries.
    }
  }, 2000)
  activeTransferPolls.add(interval)
}

const refreshAfterTransfer = async (backup) => {
  if (backup.type === 'site') {
    await fetchSiteBackups()
  } else if (backup.type === 'mail' || backup.accounts !== undefined) {
    await fetchMailBackups()
  } else {
    await fetchBackups(pagination.value.page, true)
  }
}

// ============================================
// Helpers
// ============================================
const formatDate = (dateStr) => {
  return new Date(dateStr).toLocaleString()
}

const openCreateBackupModal = async () => {
  createBackupModal.value = true
  if (categories.value.length === 0) {
    await fetchCategories()
  }
}

const openCreateScheduleModal = async () => {
  createScheduleModal.value = true
  // Fetch both categories and sites for the modal
  const promises = []
  if (categories.value.length === 0) {
    promises.push(fetchCategories())
  }
  if (sites.value.length === 0) {
    promises.push(fetchSites())
  }
  await Promise.all(promises)
}

const onTabChange = (tab) => {
  activeTab.value = tab
  
  if (tab === 'schedules' && schedules.value.length === 0) {
    fetchSchedules()
  }
  
  if (tab === 'mail') {
    fetchMailBackups()
  }
  
  if (tab === 'sites' && siteBackups.value.length === 0) {
    fetchSiteBackups()
  }
  
  if (tab === 'nas') {
    if (nasConnections.value.length === 0) {
      fetchNasConnections().then(() => {
        // After loading NAS connections, fetch remote backups
        if (nasConnections.value.length > 0) {
          fetchRemoteBackups()
        }
      })
    } else {
      // NAS connections already loaded, just refresh backups for current tab
      fetchRemoteBackups()
    }
  }
}

const frequencyLabels = {
  hourly: 'Hourly',
  daily: 'Daily',
  weekly: 'Weekly',
  monthly: 'Monthly',
  custom: 'Custom'
}

// ============================================
// Site Backup Functions
// ============================================
const fetchSites = async () => {
  sitesLoading.value = true
  try {
    const response = await api.get('/sites')
    if (response.data.success) {
      sites.value = response.data.data.vhosts || []
    }
  } catch (e) {
    // Ignore
  } finally {
    sitesLoading.value = false
  }
}

const fetchSiteBackups = async (domain = null) => {
  siteBackupsLoading.value = true
  try {
    const url = domain ? `/backups/sites/${domain}` : '/backups/sites'
    const response = await api.get(url)
    if (response.data.success) {
      siteBackups.value = response.data.data.backups || []
    }
  } catch (e) {
    toast.error('Failed to load site backups')
  } finally {
    siteBackupsLoading.value = false
  }
}

const backupSite = async (domain, destination = 'local') => {
  // Check NAS config if destination requires it
  if ((destination === 'nas' || destination === 'both') && nasConnections.value.length === 0) {
    toast.error('No NAS connections available. Please configure NAS in NAS Storage page first.')
    return
  }

  // Close the site selection modal
  siteBackupModal.value = { show: false, domain: null, destination: 'local' }
  
  // Initialize progress modal
  backupProgress.value = {
    show: true,
    domain,
    statusId: null,
    status: 'running',
    step: 'initializing',
    progress: 0,
    message: 'Starting backup...',
    startedAt: new Date().toISOString(),
    result: null,
  }

  try {
    // Start async backup
    const response = await api.post(`/backups/sites/${domain}`, { 
      destination,
      async: true 
    })
    
    if (response.data.success && response.data.data?.status_id) {
      backupProgress.value.statusId = response.data.data.status_id
      backupProgress.value.message = 'Backup started, preparing files...'
      
      // Start polling for progress
      backupProgressInterval = setInterval(() => pollBackupProgress(domain), 1500)
    } else {
      backupProgress.value.status = 'failed'
      backupProgress.value.message = response.data.error || 'Failed to start backup'
    }
  } catch (e) {
    backupProgress.value.status = 'failed'
    backupProgress.value.message = e.response?.data?.error || 'Failed to start backup'
  }
}

// Poll backup progress from server
const pollBackupProgress = async (domain) => {
  try {
    const response = await api.get('/backups/status', { 
      params: { domain } 
    })
    
    if (response.data.success) {
      const data = response.data.data
      backupProgress.value.step = data.step || 'initializing'
      backupProgress.value.progress = data.progress || 0
      backupProgress.value.message = data.message || 'Processing...'
      backupProgress.value.status = data.status || 'running'
      
      // Check if backup is complete
      if (data.status !== 'running') {
        clearInterval(backupProgressInterval)
        backupProgressInterval = null
        
        if (data.status === 'completed') {
          backupProgress.value.result = data.result
          toast.success(`Backup completed for ${domain}`)
          // Refresh backup lists
          await fetchSiteBackups()
          await fetchRemoteBackups()
        } else if (data.status === 'failed') {
          toast.error(data.result?.error || 'Backup failed')
        }
      }
    }
  } catch (e) {
    console.error('Failed to poll backup progress', e)
    // Don't stop polling on transient errors
  }
}

// Close backup progress modal
const closeBackupProgress = () => {
  if (backupProgressInterval) {
    clearInterval(backupProgressInterval)
    backupProgressInterval = null
  }
  backupProgress.value.show = false
}

// Close mail backup progress modal
const closeMailBackupProgress = () => {
  if (mailBackupProgressInterval) {
    clearInterval(mailBackupProgressInterval)
    mailBackupProgressInterval = null
  }
  mailBackupProgress.value.show = false
}

// Get step status for progress display
const getStepStatus = (stepId) => {
  const stepOrder = ['initializing', 'files', 'database', 'config', 'archiving', 'uploading']
  const currentIdx = stepOrder.indexOf(backupProgress.value.step)
  const stepIdx = stepOrder.indexOf(stepId)
  
  if (backupProgress.value.status === 'completed') return 'done'
  if (backupProgress.value.status === 'failed') {
    return stepIdx <= currentIdx ? (stepIdx === currentIdx ? 'failed' : 'done') : 'pending'
  }
  if (stepIdx < currentIdx) return 'done'
  if (stepIdx === currentIdx) return 'active'
  return 'pending'
}

// Get step status for EMAIL backup progress display
const getMailStepStatus = (stepId) => {
  const stepOrder = ['initializing', 'mailboxes', 'accounts', 'dkim', 'archiving', 'uploading', 'completed']
  const currentIdx = stepOrder.indexOf(mailBackupProgress.value.step)
  const stepIdx = stepOrder.indexOf(stepId)
  
  if (mailBackupProgress.value.status === 'completed') return 'done'
  if (mailBackupProgress.value.status === 'failed') {
    return stepIdx <= currentIdx ? (stepIdx === currentIdx ? 'failed' : 'done') : 'pending'
  }
  if (stepIdx < currentIdx) return 'done'
  if (stepIdx === currentIdx) return 'active'
  return 'pending'
}

// Open site restore modal and load inspection data
const openSiteRestoreModal = async (backup) => {
  restoreSiteModal.value = {
    show: true,
    backup,
    loading: true,
    contents: null,
    restoreFiles: true,
    fileComponents: [], // Empty = all
    restoreDatabase: true,
    selectedDatabases: [], // Empty = all
    restoreVhost: false,
    restoreSsl: false,
    restoreDns: false,
    restoreMail: false,
    mode: 'merge',
  }
  
  try {
    const response = await api.get(`/backups/inspect/site/${backup.id}`)
    if (response.data.success) {
      restoreSiteModal.value.contents = response.data.data
      // Pre-select all databases if available
      if (response.data.data.databases?.length) {
        restoreSiteModal.value.selectedDatabases = response.data.data.databases.map(db => db.name)
      }
    } else {
      toast.error(response.data.error || 'Failed to inspect backup')
      restoreSiteModal.value.show = false
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to inspect backup')
    restoreSiteModal.value.show = false
  } finally {
    restoreSiteModal.value.loading = false
  }
}

// Toggle file component selection
const toggleFileComponent = (component) => {
  const idx = restoreSiteModal.value.fileComponents.indexOf(component)
  if (idx === -1) {
    restoreSiteModal.value.fileComponents.push(component)
  } else {
    restoreSiteModal.value.fileComponents.splice(idx, 1)
  }
}

// Toggle database selection
const toggleDatabase = (dbName) => {
  const idx = restoreSiteModal.value.selectedDatabases.indexOf(dbName)
  if (idx === -1) {
    restoreSiteModal.value.selectedDatabases.push(dbName)
  } else {
    restoreSiteModal.value.selectedDatabases.splice(idx, 1)
  }
}

// Reset restore modal to initial state
const resetRestoreSiteModal = () => {
  restoreSiteModal.value = { 
    show: false, backup: null, loading: false, contents: null,
    restoreFiles: true, fileComponents: [], restoreDatabase: true, selectedDatabases: [],
    restoreVhost: false, restoreSsl: false, restoreDns: false, restoreMail: false, mode: 'merge',
    dryRunning: false, dryRunLogs: null, dryRunAnalysis: null
  }
}

// Dry run restore - analyze what would happen without making changes
const dryRunRestore = async () => {
  if (!restoreSiteModal.value.backup) return
  
  const modal = restoreSiteModal.value
  modal.dryRunning = true
  modal.dryRunLogs = null
  modal.dryRunAnalysis = null
  
  try {
    const backup = modal.backup
    
    // Build restore options (same as actual restore)
    const restoreFiles = modal.restoreFiles 
      ? (modal.fileComponents.length > 0 ? modal.fileComponents : true)
      : false
    
    const restoreDatabase = modal.restoreDatabase
      ? (modal.selectedDatabases.length > 0 ? modal.selectedDatabases : true)
      : false
    
    const response = await api.post(`/backups/sites/${backup.domain}/restore`, {
      backup_id: backup.id,
      restore_files: restoreFiles,
      restore_database: restoreDatabase,
      restore_config: modal.restoreVhost,
      restore_ssl: modal.restoreSsl,
      restore_dns: modal.restoreDns,
      restore_mail: modal.restoreMail,
      mode: modal.mode,
      dry_run: true, // This is the key difference!
    })
    
    if (response.data.success) {
      const data = response.data.data
      modal.dryRunLogs = data.logs || response.data.logs || []
      modal.dryRunAnalysis = data.analysis || null
      toast.success('Dry run complete - check the logs below')
    } else {
      modal.dryRunLogs = response.data.logs || [
        { time: new Date().toLocaleTimeString(), level: 'error', message: response.data.error || 'Dry run failed' }
      ]
      toast.error(response.data.error || 'Dry run failed')
    }
  } catch (e) {
    modal.dryRunLogs = [
      { time: new Date().toLocaleTimeString(), level: 'error', message: e.response?.data?.error || e.message || 'Dry run failed' }
    ]
    toast.error(e.response?.data?.error || 'Dry run failed')
  } finally {
    modal.dryRunning = false
  }
}

// Restore site backup with selected components
const restoreSiteBackup = async () => {
  if (!restoreSiteModal.value.backup) return
  
  submitting.value = true
  try {
    const backup = restoreSiteModal.value.backup
    const modal = restoreSiteModal.value
    
    // Build restore options
    const restoreFiles = modal.restoreFiles 
      ? (modal.fileComponents.length > 0 ? modal.fileComponents : true)
      : false
    
    const restoreDatabase = modal.restoreDatabase
      ? (modal.selectedDatabases.length > 0 ? modal.selectedDatabases : true)
      : false
    
    const response = await api.post(`/backups/sites/${backup.domain}/restore`, {
      backup_id: backup.id,
      restore_files: restoreFiles,
      restore_database: restoreDatabase,
      restore_config: modal.restoreVhost,
      restore_ssl: modal.restoreSsl,
      restore_dns: modal.restoreDns,
      restore_mail: modal.restoreMail,
      mode: modal.mode,
    })
    
    if (response.data.success) {
      const data = response.data.data
      const restored = data.restored?.length || 0
      const errors = data.errors?.length || 0
      
      // Show logs if available
      if (data.logs?.length > 0) {
        restoreSiteModal.value.dryRunLogs = data.logs
      }
      
      if (errors > 0) {
        toast.warning(`${restored} components restored, ${errors} errors - check logs for details`)
      } else {
        toast.success(`${restored} components restored successfully`)
      }
      
      // Show pre-restore backup location
      if (data.pre_restore_backup) {
        toast.info(`Pre-restore backup saved to: ${data.pre_restore_backup}`, { duration: 10000 })
      }
      
      // Don't close modal if there were errors so user can see logs
      if (errors === 0) {
        resetRestoreSiteModal()
      }
    } else {
      // Show logs even on failure
      if (response.data.logs?.length > 0) {
        restoreSiteModal.value.dryRunLogs = response.data.logs
      }
      toast.error(response.data.error || 'Restore failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Restore failed')
  } finally {
    submitting.value = false
  }
}

// =========================================================================
// NAS Remote Backup Functions
// =========================================================================

const fetchNasConnections = async () => {
  nasLoading.value = true
  try {
    const response = await api.get('/backups/nas/connections')
    if (response.data.success) {
      nasConnections.value = response.data.data.connections || []
      // Auto-select first connection if none selected
      if (nasConnections.value.length > 0 && !selectedNasId.value) {
        const defaultNas = nasConnections.value.find(n => n.is_default) || nasConnections.value[0]
        selectedNasId.value = defaultNas.id
      }
    }
  } catch (e) {
    // NAS might not be configured yet, that's okay
  } finally {
    nasLoading.value = false
  }
}

const fetchRemoteBackups = async (type = null) => {
  remoteBackupsLoading.value = true
  selectedNasBackups.value = [] // Clear selection on refresh
  try {
    const params = { _t: Date.now() } // Cache bust
    if (selectedNasId.value) {
      params.nas_id = selectedNasId.value
    }
    // Use provided type or current active tab. Guard against being used
    // directly as an event handler (a DOM Event is not a type filter).
    const backupType = (typeof type === 'string' && type) ? type : activeNasTab.value
    if (backupType) {
      params.type = backupType
    }
    const response = await api.get('/backups/nas/list', { params })
    if (response.data.success) {
      remoteBackups.value = response.data.data.backups || []
      const warnings = response.data.data.warnings || []
      if (warnings.length > 0) {
        toast.warning(`Some NAS folders could not be read: ${warnings.join('; ')}`)
      }
    }
  } catch (e) {
    const reason = e.response?.data?.error
    toast.error(reason ? `Failed to load NAS backups: ${reason}` : 'Failed to load NAS backups')
  } finally {
    remoteBackupsLoading.value = false
  }
}

const onNasTabChange = (tab) => {
  activeNasTab.value = tab
  fetchRemoteBackups(tab)
}

// NAS backup selection
const toggleNasBackupSelection = (path) => {
  const idx = selectedNasBackups.value.indexOf(path)
  if (idx === -1) {
    selectedNasBackups.value.push(path)
  } else {
    selectedNasBackups.value.splice(idx, 1)
  }
}

const toggleAllNasBackups = () => {
  if (selectedNasBackups.value.length === remoteBackups.value.length) {
    selectedNasBackups.value = []
  } else {
    selectedNasBackups.value = remoteBackups.value.map(b => b.path)
  }
}

const allNasBackupsSelected = computed(() => {
  return remoteBackups.value.length > 0 && selectedNasBackups.value.length === remoteBackups.value.length
})

const deleteSelectedNasBackups = async () => {
  if (selectedNasBackups.value.length === 0) return
  if (deleteNasConfirmText.value !== 'DELETE') return
  
  deletingNasBackups.value = true
  try {
    const response = await api.post('/backups/nas/delete', {
      paths: selectedNasBackups.value,
      nas_id: selectedNasId.value
    })
    if (response.data.success) {
      const data = response.data.data
      toast.success(`${data.deleted_count} backup(s) deleted from NAS`)
      if (data.error_count > 0) {
        toast.warning(`${data.error_count} file(s) could not be deleted`)
      }
      selectedNasBackups.value = []
      deleteNasModal.value.show = false
      deleteNasConfirmText.value = ''
      
      // Refresh both NAS backups and config backups (to update NAS badge status)
      await Promise.all([
        fetchRemoteBackups(),
        fetchBackups(1, true) // Force refresh config backups
      ])
    } else {
      toast.error(response.data.error || 'Failed to delete backups')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete backups')
  } finally {
    deletingNasBackups.value = false
  }
}

const formatBytes = (bytes) => {
  if (!bytes || bytes === 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let i = 0
  while (bytes >= 1024 && i < units.length - 1) {
    bytes /= 1024
    i++
  }
  return `${bytes.toFixed(2)} ${units[i]}`
}

const openSiteBackupModal = async () => {
  siteBackupModal.value = { show: true, domain: null }
  if (sites.value.length === 0) {
    await fetchSites()
  }
}

// ============================================
// Email Backup Functions
// ============================================
const fetchMailDomains = async () => {
  mailDomainsLoading.value = true
  try {
    const response = await api.get('/backups/mail/domains')
    if (response.data.success) {
      mailDomains.value = response.data.data.domains || []
    }
  } catch (e) {
    toast.error('Failed to load mail domains')
  } finally {
    mailDomainsLoading.value = false
  }
}

const fetchMailBackups = async (domain = null) => {
  mailBackupsLoading.value = true
  try {
    const url = domain ? `/backups/mail/${domain}` : '/backups/mail'
    const response = await api.get(url)
    if (response.data.success) {
      mailBackups.value = response.data.data.backups || []
    }
  } catch (e) {
    toast.error('Failed to load email backups')
  } finally {
    mailBackupsLoading.value = false
  }
}

const openMailBackupModal = async () => {
  mailBackupModal.value = { show: true, domain: null, destination: 'local' }
  if (mailDomains.value.length === 0) {
    await fetchMailDomains()
  }
}

const backupMail = async (domain, destination = 'local') => {
  if ((destination === 'nas' || destination === 'both') && nasConnections.value.length === 0) {
    toast.error('No NAS connections available. Please configure NAS in NAS Storage page first.')
    return
  }

  // Close the domain selection modal
  mailBackupModal.value = { show: false, domain: null, destination: 'local' }
  
  // Initialize email-specific progress modal
  mailBackupProgress.value = {
    show: true,
    domain,
    statusId: null,
    status: 'running',
    step: 'initializing',
    progress: 0,
    message: 'Starting mail backup...',
    startedAt: new Date().toISOString(),
    result: null,
  }

  try {
    // Async: the agent spawns a detached runner and returns a status_id
    // immediately; we poll for progress (same pattern as site backups).
    const response = await api.post(`/backups/mail/${domain}`, { 
      destination,
      async: true
    })
    
    if (response.data.success && response.data.data?.status_id) {
      mailBackupProgress.value.statusId = response.data.data.status_id
      mailBackupProgress.value.message = 'Backup started, copying mailboxes...'

      mailBackupProgressInterval = setInterval(
        () => pollMailBackupProgress(domain, response.data.data.status_id),
        1500
      )
    } else {
      mailBackupProgress.value.status = 'failed'
      mailBackupProgress.value.message = response.data.error || 'Failed to start backup'
    }
  } catch (e) {
    mailBackupProgress.value.status = 'failed'
    mailBackupProgress.value.message = e.response?.data?.error || 'Backup failed'
  }
}

// Poll mail backup progress from server
const pollMailBackupProgress = async (domain, statusId) => {
  try {
    const response = await api.get('/backups/status', {
      params: { status_id: statusId }
    })

    if (response.data.success) {
      const data = response.data.data
      mailBackupProgress.value.step = data.step || 'initializing'
      mailBackupProgress.value.progress = data.progress || 0
      mailBackupProgress.value.message = data.message || 'Processing...'
      mailBackupProgress.value.status = data.status || 'running'

      if (data.status !== 'running') {
        clearInterval(mailBackupProgressInterval)
        mailBackupProgressInterval = null

        if (data.status === 'completed') {
          mailBackupProgress.value.progress = 100
          mailBackupProgress.value.result = data.result
          toast.success(`Email backup completed for ${domain}`)
          await fetchMailBackups()
        } else if (data.status === 'failed') {
          toast.error(data.result?.error || data.message || 'Mail backup failed')
        }
      }
    }
  } catch (e) {
    // Transient poll errors are ignored; the next tick retries.
  }
}

const openMailRestoreModal = async (backup) => {
  restoreMailModal.value = {
    show: true,
    backup,
    loading: true,
    contents: null,
    restoreMailboxes: true,
    restoreAccounts: true,
    restoreDkim: true,
    mode: 'merge',
    dryRunning: false,
    dryRunLogs: null,
    dryRunAnalysis: null,
  }
  
  try {
    const response = await api.get(`/backups/mail/inspect/${backup.id}`)
    if (response.data.success) {
      restoreMailModal.value.contents = response.data.data
    } else {
      toast.error(response.data.error || 'Failed to inspect backup')
      restoreMailModal.value.show = false
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to inspect backup')
    restoreMailModal.value.show = false
  } finally {
    restoreMailModal.value.loading = false
  }
}

const restoreMailBackup = async () => {
  if (!restoreMailModal.value.backup) return
  
  submitting.value = true
  try {
    const backup = restoreMailModal.value.backup
    const modal = restoreMailModal.value
    
    const response = await api.post(`/backups/mail/${backup.domain}/restore`, {
      backup_id: backup.id,
      restore_mailboxes: modal.restoreMailboxes,
      restore_accounts: modal.restoreAccounts,
      restore_dkim: modal.restoreDkim,
      mode: modal.mode,
    })
    
    if (response.data.success) {
      toast.success(`Email restored for ${backup.domain}`)
      restoreMailModal.value.show = false
    } else {
      toast.error(response.data.error || 'Restore failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Restore failed')
  } finally {
    submitting.value = false
  }
}

const dryRunMailRestore = async () => {
  if (!restoreMailModal.value.backup) return
  
  restoreMailModal.value.dryRunning = true
  restoreMailModal.value.dryRunLogs = null
  restoreMailModal.value.dryRunAnalysis = null
  
  try {
    const backup = restoreMailModal.value.backup
    const modal = restoreMailModal.value
    
    const response = await api.post(`/backups/mail/${backup.domain}/restore`, {
      backup_id: backup.id,
      restore_mailboxes: modal.restoreMailboxes,
      restore_accounts: modal.restoreAccounts,
      restore_dkim: modal.restoreDkim,
      mode: modal.mode,
      dry_run: true,
    })
    
    if (response.data.success) {
      restoreMailModal.value.dryRunLogs = response.data.data.logs || []
      restoreMailModal.value.dryRunAnalysis = response.data.data.analysis || null
    } else {
      toast.error(response.data.error || 'Dry run failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Dry run failed')
  } finally {
    restoreMailModal.value.dryRunning = false
  }
}

// ============================================
// Init
// ============================================
onMounted(() => {
  fetchBackups()
  fetchNasConnections() // Load NAS connections to enable/disable destination buttons
})

// Cleanup interval on component unmount
onUnmounted(() => {
  if (backupProgressInterval) {
    clearInterval(backupProgressInterval)
    backupProgressInterval = null
  }
  if (mailBackupProgressInterval) {
    clearInterval(mailBackupProgressInterval)
    mailBackupProgressInterval = null
  }
  for (const interval of activeTransferPolls) {
    clearInterval(interval)
  }
  activeTransferPolls.clear()
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Backups</h1>
        <p class="text-surface-500 text-sm mt-1 hidden sm:block">Configuration backups and scheduled backup management</p>
      </div>
      <div class="flex gap-2">
        <button @click="cleanupModal.show = true" class="btn-secondary">
          <span class="material-symbols-rounded">delete_sweep</span>
          <span class="hidden sm:inline">Cleanup Old</span>
        </button>
        <button @click="openCreateBackupModal" class="btn-primary">
          <span class="material-symbols-rounded">add</span>
          <span class="hidden sm:inline">Create Backup</span>
        </button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-surface-200 dark:border-surface-700 mb-6 overflow-x-auto scrollbar-none">
      <nav class="flex gap-1 -mb-px min-w-max">
        <button
          @click="onTabChange('backups')"
          :class="[
            'flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
            activeTab === 'backups'
              ? 'border-primary-500 text-primary-600 dark:text-primary-400'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
          title="Config Backups"
        >
          <span class="material-symbols-rounded text-lg">backup</span>
          <span class="hidden sm:inline">Config Backups</span>
        </button>
        <button
          @click="onTabChange('sites')"
          :class="[
            'flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
            activeTab === 'sites'
              ? 'border-primary-500 text-primary-600 dark:text-primary-400'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
          title="Site Backups"
        >
          <span class="material-symbols-rounded text-lg">language</span>
          <span class="hidden sm:inline">Site Backups</span>
        </button>
        <button
          @click="onTabChange('mail')"
          :class="[
            'flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
            activeTab === 'mail'
              ? 'border-primary-500 text-primary-600 dark:text-primary-400'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
          title="Email Backups"
        >
          <span class="material-symbols-rounded text-lg">mail</span>
          <span class="hidden sm:inline">Email Backups</span>
        </button>
        <button
          @click="onTabChange('schedules')"
          :class="[
            'flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
            activeTab === 'schedules'
              ? 'border-primary-500 text-primary-600 dark:text-primary-400'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
          title="Scheduled Backups"
        >
          <span class="material-symbols-rounded text-lg">schedule</span>
          <span class="hidden sm:inline">Scheduled</span>
        </button>
        <button
          @click="onTabChange('nas')"
          :class="[
            'flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
            activeTab === 'nas'
              ? 'border-primary-500 text-primary-600 dark:text-primary-400'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
          title="NAS Storage"
        >
          <span class="material-symbols-rounded text-lg">hard_drive</span>
          <span class="hidden sm:inline">NAS</span>
        </button>
      </nav>
    </div>

    <!-- ============================================ -->
    <!-- Backups Tab -->
    <!-- ============================================ -->
    <div v-if="activeTab === 'backups'">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <!-- Backups table -->
      <div v-else class="card overflow-hidden">
        <!-- Selection header -->
        <div v-if="selectedBackups.length > 0" class="p-3 bg-primary-50 dark:bg-primary-500/10 border-b border-primary-200 dark:border-primary-500/20 flex items-center justify-between">
          <span class="text-sm font-medium text-primary-700 dark:text-primary-300">
            {{ selectedBackups.length }} backup(s) selected
          </span>
          <button 
            @click="deleteMultipleModal.show = true" 
            class="btn-danger btn-sm"
          >
            <span class="material-symbols-rounded">delete</span>
            Delete Selected
          </button>
        </div>
        <div class="overflow-x-auto">
          <table class="table min-w-[700px]">
            <thead>
              <tr class="bg-surface-50 dark:bg-surface-800/50">
                <th class="w-12">
                  <button 
                    @click="toggleAllBackups" 
                    class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors"
                    :class="allBackupsSelected 
                      ? 'bg-primary-500 border-primary-500 text-white' 
                      : 'border-surface-300 dark:border-surface-600 hover:border-primary-400'"
                  >
                    <span v-if="allBackupsSelected" class="material-symbols-rounded text-sm">check</span>
                  </button>
                </th>
                <th>File</th>
                <th class="hidden sm:table-cell">Contents</th>
                <th class="hidden md:table-cell">Location</th>
                <th>Size</th>
                <th class="hidden sm:table-cell">Date</th>
                <th class="text-right">Actions</th>
              </tr>
            </thead>
          <tbody>
            <tr 
              v-for="backup in backups" 
              :key="backup.id"
              :class="selectedBackups.includes(backup.id) ? 'bg-primary-50 dark:bg-primary-500/10' : ''"
            >
              <td>
                <button 
                  @click="toggleBackupSelection(backup.id)" 
                  class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors"
                  :class="selectedBackups.includes(backup.id) 
                    ? 'bg-primary-500 border-primary-500 text-white' 
                    : 'border-surface-300 dark:border-surface-600 hover:border-primary-400'"
                >
                  <span v-if="selectedBackups.includes(backup.id)" class="material-symbols-rounded text-sm">check</span>
                </button>
              </td>
              <td>
                <span class="font-mono text-sm">{{ backup.filename }}</span>
              </td>
              <td>
                <div v-if="backup.contents && backup.contents.length" class="flex flex-wrap gap-1">
                  <span 
                    v-for="(item, idx) in backup.contents.slice(0, 3)" 
                    :key="idx"
                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300"
                  >
                    {{ item }}
                  </span>
                  <span 
                    v-if="backup.contents.length > 3"
                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-300"
                    :title="backup.contents.slice(3).join(', ')"
                  >
                    +{{ backup.contents.length - 3 }} more
                  </span>
                </div>
                <span v-else-if="backup.original_path" class="text-sm text-surface-500 font-mono">
                  {{ backup.original_path.split('/').pop() }}
                </span>
                <span v-else class="text-surface-400">-</span>
              </td>
              <td>
                <div class="flex items-center gap-1">
                  <BackupLocationBadge :location="backup.location" :nas-uploaded="backup.nas_uploaded" />
                  <!-- Show warning if a NAS upload was requested but failed -->
                  <span 
                    v-if="(backup.destination === 'nas' || backup.destination === 'both') && !backup.nas_uploaded"
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300"
                    title="NAS upload failed - still on server only"
                  >
                    <span class="material-symbols-rounded text-sm">error</span>
                    Failed
                  </span>
                </div>
              </td>
              <td>
                <span class="text-surface-500">{{ backup.size_human }}</span>
              </td>
              <td>
                <span class="text-sm text-surface-500">{{ formatDate(backup.date) }}</span>
              </td>
              <td class="text-right">
                <div class="flex justify-end gap-1">
                  <button
                    v-if="backup.location !== 'nas' && !backup.nas_uploaded"
                    @click="openTransferModal(backup)"
                    class="btn-ghost btn-sm text-cyan-500"
                    title="Send to NAS"
                  >
                    <span class="material-symbols-rounded">cloud_upload</span>
                  </button>
                  <button
                    @click="downloadBackup(backup)"
                    class="btn-ghost btn-sm text-blue-500"
                    title="Download"
                  >
                    <span class="material-symbols-rounded">download</span>
                  </button>
                  <button
                    @click="openRestoreModal(backup)"
                    class="btn-ghost btn-sm text-primary-500"
                    title="Restore"
                  >
                    <span class="material-symbols-rounded">settings_backup_restore</span>
                  </button>
                  <button
                    @click="deleteModal = { show: true, backup }"
                    class="btn-ghost btn-sm text-red-500"
                    title="Delete"
                  >
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!backups.length">
              <td colspan="6" class="py-12 text-center text-surface-400">
                <span class="material-symbols-rounded text-4xl mb-2 block">backup</span>
                No backups found
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Legend for existing backups without metadata -->
        <div v-if="backups.some(b => !b.contents && b.type === 'archive')" class="px-4 py-2 bg-amber-50 dark:bg-amber-500/10 border-t border-surface-100 dark:border-surface-800">
          <p class="text-xs text-amber-700 dark:text-amber-300 flex items-center gap-1">
            <span class="material-symbols-rounded text-sm">info</span>
            Older backups without metadata will show contents after they are recreated.
          </p>
        </div>

        <!-- Pagination -->
        <div v-if="pagination.total_pages > 1" class="px-4 py-3 border-t border-surface-100 dark:border-surface-800 flex items-center justify-between">
          <span class="text-sm text-surface-500">
            Page {{ pagination.page }} of {{ pagination.total_pages }}
          </span>
          <div class="flex gap-2">
            <button
              @click="fetchBackups(pagination.page - 1)"
              :disabled="pagination.page <= 1"
              class="btn-secondary btn-sm"
            >
              Previous
            </button>
            <button
              @click="fetchBackups(pagination.page + 1)"
              :disabled="pagination.page >= pagination.total_pages"
              class="btn-secondary btn-sm"
            >
              Next
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ============================================ -->
    <!-- Site Backups Tab -->
    <!-- ============================================ -->
    <div v-else-if="activeTab === 'sites'">
      <!-- Header Actions -->
      <div class="flex justify-end mb-6">
        <button @click="openSiteBackupModal" class="btn-primary">
          <span class="material-symbols-rounded">add</span>
          Backup Site
        </button>
      </div>

      <!-- Loading -->
      <div v-if="siteBackupsLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <!-- Site backups table -->
      <div v-else class="card overflow-hidden">
        <table class="table">
          <thead>
            <tr class="bg-surface-50 dark:bg-surface-800/50">
              <th>Site</th>
              <th>Backup File</th>
              <th>Location</th>
              <th>Size</th>
              <th>Date</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="backup in siteBackups" :key="backup.id">
              <td>
                <div class="flex items-center gap-2">
                  <span class="material-symbols-rounded text-primary-500">language</span>
                  <span class="font-medium">{{ backup.domain }}</span>
                </div>
              </td>
              <td>
                <span class="font-mono text-sm text-surface-500">{{ backup.filename }}</span>
              </td>
              <td>
                <div class="flex items-center gap-1.5">
                  <BackupLocationBadge :location="backup.location" :nas-uploaded="backup.nas_uploaded" />
                  <span
                    v-if="backup.location === 'nas' && backup.available === false"
                    class="material-symbols-rounded text-sm text-amber-500"
                    title="NAS is not reachable right now - this backup is temporarily unavailable"
                  >cloud_off</span>
                </div>
              </td>
              <td>
                <div class="flex items-center gap-1.5">
                  <span class="text-surface-500">{{ backup.size_human }}</span>
                  <span
                    v-if="backup.split"
                    class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-violet-100 dark:bg-violet-500/15 text-violet-600 dark:text-violet-300"
                    :title="`Large backup stored as ${backup.parts_count} split parts`"
                  >{{ backup.parts_count }} parts</span>
                </div>
              </td>
              <td>
                <span class="text-sm text-surface-500">{{ formatDate(backup.date) }}</span>
              </td>
              <td class="text-right">
                <div class="flex justify-end gap-1">
                  <button
                    v-if="backup.location !== 'nas'"
                    @click="openTransferModal(backup)"
                    class="btn-ghost btn-sm text-cyan-500"
                    :title="backup.location === 'both' ? 'Already on NAS' : 'Send to NAS'"
                    :disabled="backup.location === 'both'"
                    :class="{ 'opacity-40 cursor-not-allowed': backup.location === 'both' }"
                  >
                    <span class="material-symbols-rounded">cloud_upload</span>
                  </button>
                  <button
                    @click="downloadBackup(backup)"
                    class="btn-ghost btn-sm text-blue-500"
                    :title="backup.split ? 'Split archive - use Restore, or fetch the parts from the NAS share' : 'Download'"
                    :disabled="backup.available === false || backup.split"
                    :class="{ 'opacity-40 cursor-not-allowed': backup.available === false || backup.split }"
                  >
                    <span class="material-symbols-rounded">download</span>
                  </button>
                  <button
                    @click="openSiteRestoreModal(backup)"
                    class="btn-ghost btn-sm text-primary-500"
                    title="Restore"
                    :disabled="backup.available === false"
                    :class="{ 'opacity-40 cursor-not-allowed': backup.available === false }"
                  >
                    <span class="material-symbols-rounded">settings_backup_restore</span>
                  </button>
                  <button
                    @click="deleteModal = { show: true, backup }"
                    class="btn-ghost btn-sm text-red-500"
                    title="Delete"
                    :disabled="backup.available === false"
                    :class="{ 'opacity-40 cursor-not-allowed': backup.available === false }"
                  >
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!siteBackups.length">
              <td colspan="6" class="py-12 text-center text-surface-400">
                <span class="material-symbols-rounded text-4xl mb-2 block">language</span>
                <p>No site backups found</p>
                <button @click="openSiteBackupModal" class="btn-primary mt-4">
                  Create Your First Site Backup
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ============================================ -->
    <!-- Email Backups Tab -->
    <!-- ============================================ -->
    <div v-else-if="activeTab === 'mail'">
      <!-- Header Actions -->
      <div class="flex justify-end mb-6">
        <button @click="openMailBackupModal" class="btn-primary">
          <span class="material-symbols-rounded">add</span>
          Backup Emails
        </button>
      </div>

      <!-- Loading -->
      <div v-if="mailBackupsLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <!-- Email backups table -->
      <div v-else class="card overflow-hidden">
        <table class="table">
          <thead>
            <tr class="bg-surface-50 dark:bg-surface-800/50">
              <th>Domain</th>
              <th>Backup File</th>
              <th>Accounts</th>
              <th>Size</th>
              <th>Date</th>
              <th>NAS</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="mailBackups.length === 0">
              <td colspan="7" class="text-center py-8 text-surface-400">
                <span class="material-symbols-rounded text-4xl block mb-2">mail</span>
                No email backups found. Create your first backup to get started.
              </td>
            </tr>
            <tr v-for="backup in mailBackups" :key="backup.id">
              <td>
                <div class="flex items-center gap-2">
                  <span class="material-symbols-rounded text-primary-500">mail</span>
                  <span class="font-medium">{{ backup.domain }}</span>
                </div>
              </td>
              <td class="text-sm text-surface-500 font-mono">{{ backup.filename }}</td>
              <td>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300">
                  <span class="material-symbols-rounded text-sm">person</span>
                  {{ backup.accounts || '?' }}
                </span>
              </td>
              <td class="text-sm">{{ backup.size_human }}</td>
              <td class="text-sm text-surface-500">{{ backup.date }}</td>
              <td>
                <!-- NAS Only badge (not available locally) -->
                <span 
                  v-if="backup.nas_only" 
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300"
                  title="Available only on NAS (not stored locally)"
                >
                  <span class="material-symbols-rounded text-sm">cloud</span>
                  NAS Only
                </span>
                <!-- Uploaded to NAS (also available locally) -->
                <span 
                  v-else-if="backup.nas_uploaded" 
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300"
                  title="Uploaded to NAS"
                >
                  <span class="material-symbols-rounded text-sm">cloud_done</span>
                  NAS
                </span>
                <!-- Local only -->
                <span 
                  v-else 
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-300"
                  title="Local only"
                >
                  <span class="material-symbols-rounded text-sm">folder</span>
                  Local
                </span>
              </td>
              <td class="text-right">
                <div class="flex justify-end gap-2">
                  <button
                    v-if="!backup.nas_only && !backup.nas_uploaded"
                    @click="openTransferModal(backup)"
                    class="btn-ghost btn-sm text-cyan-500"
                    title="Send to NAS"
                  >
                    <span class="material-symbols-rounded">cloud_upload</span>
                  </button>
                  <button 
                    @click="openMailRestoreModal(backup)" 
                    class="btn-secondary btn-sm" 
                    :title="backup.nas_only ? 'Restore from NAS' : 'Restore'"
                  >
                    <span class="material-symbols-rounded">settings_backup_restore</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ============================================ -->
    <!-- Schedules Tab -->
    <!-- ============================================ -->
    <div v-else-if="activeTab === 'schedules'">
      <!-- Header Actions -->
      <div class="flex justify-end mb-6">
        <button @click="openCreateScheduleModal" class="btn-primary">
          <span class="material-symbols-rounded">add</span>
          Create Schedule
        </button>
      </div>

      <!-- Cron daemon warning: schedules are dead without a running cron -->
      <div
        v-if="cronDaemon && !cronDaemon.healthy"
        class="mb-6 p-4 rounded-xl border border-red-200 dark:border-red-500/30 bg-red-50 dark:bg-red-500/10 flex items-start gap-3"
      >
        <span class="material-symbols-rounded text-red-500 mt-0.5">error</span>
        <div class="flex-1">
          <p class="font-medium text-red-700 dark:text-red-300">
            {{ !cronDaemon.installed
              ? 'No cron daemon is installed on this server - scheduled backups will never run.'
              : !cronDaemon.active
                ? 'The cron daemon is installed but not running - scheduled backups will never run.'
                : 'The backup cron file has a problem (permissions or formatting).' }}
          </p>
          <p class="text-sm text-red-600/80 dark:text-red-400/80 mt-1">
            Click Fix cron to install/enable the daemon and normalize the schedule file automatically.
          </p>
        </div>
        <button @click="repairCron" class="btn-primary btn-sm shrink-0" :disabled="repairingCron">
          <span v-if="repairingCron" class="spinner-sm"></span>
          <span v-else class="material-symbols-rounded">build</span>
          {{ repairingCron ? 'Fixing...' : 'Fix cron' }}
        </button>
      </div>

      <!-- Loading -->
      <div v-if="schedulesLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <!-- Schedules list -->
      <div v-else class="space-y-4">
        <div v-if="schedules.length === 0" class="card p-12 text-center text-surface-400">
          <span class="material-symbols-rounded text-4xl mb-2 block">schedule</span>
          <p>No backup schedules configured</p>
          <button @click="openCreateScheduleModal" class="btn-primary mt-4">
            Create Your First Schedule
          </button>
        </div>

        <div
          v-for="schedule in schedules"
          :key="schedule.id"
          :class="[
            'card p-4',
            !schedule.enabled && 'opacity-60'
          ]"
        >
          <!-- Top Row: Main Info -->
          <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-4 flex-1">
              <!-- Icon -->
              <div :class="[
                'w-12 h-12 rounded-xl flex items-center justify-center shrink-0',
                schedule.enabled 
                  ? schedule.type === 'site' ? 'bg-blue-100 dark:bg-blue-500/20' : 'bg-primary-100 dark:bg-primary-500/20' 
                  : 'bg-surface-100 dark:bg-surface-800'
              ]">
                <span :class="[
                  'material-symbols-rounded text-xl',
                  schedule.enabled 
                    ? schedule.type === 'site' ? 'text-blue-600 dark:text-blue-400' : 'text-primary-600 dark:text-primary-400' 
                    : 'text-surface-400'
                ]">{{ schedule.type === 'site' ? 'language' : 'settings' }}</span>
              </div>

              <!-- Type & Name -->
              <div class="min-w-[140px]">
                <h3 class="font-medium">{{ schedule.type === 'site' ? 'Site Backup' : 'Config Backup' }}</h3>
                <span 
                  :class="[
                    'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium mt-1',
                    schedule.type === 'site' ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' : 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300'
                  ]"
                >
                  {{ schedule.type === 'site' ? 'Site' : 'Config' }}
                </span>
              </div>

              <!-- Frequency -->
              <div class="min-w-[100px]">
                <p class="text-xs text-surface-500 mb-1">Frequency</p>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-sm font-medium bg-surface-100 dark:bg-surface-800 text-surface-700 dark:text-surface-300">
                  <span class="material-symbols-rounded text-sm">{{ 
                    schedule.frequency === 'hourly' ? 'hourglass_top' :
                    schedule.frequency === 'daily' ? 'today' :
                    schedule.frequency === 'weekly' ? 'date_range' :
                    'calendar_month'
                  }}</span>
                  {{ frequencyLabels[schedule.frequency] || schedule.frequency }}{{ schedule.frequency === 'weekly' && schedule.day_of_week_label ? ` (${schedule.day_of_week_label})` : '' }}
                </span>
              </div>

              <!-- Time -->
              <div class="min-w-[80px]">
                <p class="text-xs text-surface-500 mb-1">Time</p>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-sm font-medium bg-surface-100 dark:bg-surface-800 text-surface-700 dark:text-surface-300">
                  <span class="material-symbols-rounded text-sm">schedule</span>
                  {{ schedule.time || `${schedule.hour}:${String(schedule.minute).padStart(2, '0')}` }}
                </span>
              </div>

              <!-- Retention -->
              <div class="min-w-[80px]">
                <p class="text-xs text-surface-500 mb-1">Retention</p>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-sm font-medium bg-surface-100 dark:bg-surface-800 text-surface-700 dark:text-surface-300">
                  <span class="material-symbols-rounded text-sm">history</span>
                  {{ schedule.retention }}d
                </span>
              </div>

              <!-- Destination -->
              <div class="min-w-[100px]">
                <p class="text-xs text-surface-500 mb-1">Destination</p>
                <span 
                  :class="[
                    'inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-sm font-medium',
                    schedule.destination === 'nas' ? 'bg-cyan-100 dark:bg-cyan-500/20 text-cyan-700 dark:text-cyan-300' :
                    schedule.destination === 'both' ? 'bg-purple-100 dark:bg-purple-500/20 text-purple-700 dark:text-purple-300' :
                    'bg-surface-100 dark:bg-surface-800 text-surface-700 dark:text-surface-300'
                  ]"
                >
                  <span class="material-symbols-rounded text-sm">{{ 
                    schedule.destination === 'nas' ? 'hard_drive' : 
                    schedule.destination === 'both' ? 'sync' : 'folder' 
                  }}</span>
                  {{ schedule.destination === 'nas' ? 'NAS' : schedule.destination === 'both' ? 'Both' : 'Local' }}
                </span>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-3 shrink-0">
              <button
                @click="runScheduleNow(schedule)"
                class="btn-secondary btn-sm"
                title="Run this backup right now (in the background)"
                :disabled="runningScheduleId === schedule.id || schedule.last_status === 'running'"
              >
                <span v-if="runningScheduleId === schedule.id || schedule.last_status === 'running'" class="spinner-sm"></span>
                <span v-else class="material-symbols-rounded">play_arrow</span>
                {{ schedule.last_status === 'running' ? 'Running...' : 'Run Now' }}
              </button>
              <button
                @click="openEditScheduleModal(schedule)"
                class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 text-surface-500 hover:text-primary-500 transition-colors"
                title="Edit schedule"
              >
                <span class="material-symbols-rounded">edit</span>
              </button>
              <button
                @click="toggleSchedule(schedule)"
                :class="[
                  'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                  schedule.enabled ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
                :title="schedule.enabled ? 'Disable schedule' : 'Enable schedule'"
              >
                <span
                  :class="[
                    'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                    schedule.enabled ? 'translate-x-6' : 'translate-x-1'
                  ]"
                />
              </button>
              <button
                @click="deleteScheduleModal = { show: true, schedule }"
                class="p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 text-surface-500 hover:text-red-500 transition-colors"
                title="Delete schedule"
              >
                <span class="material-symbols-rounded">delete</span>
              </button>
            </div>
          </div>

          <!-- Bottom Row: Details (Sites/Categories/Components) -->
          <div class="mt-3 pt-3 border-t border-surface-100 dark:border-surface-800 flex items-center gap-6">
            <!-- Config categories -->
            <template v-if="schedule.type === 'config' && schedule.category_labels">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs text-surface-500 font-medium">Categories:</span>
                <span 
                  v-for="(label, idx) in schedule.category_labels.slice(0, 6)" 
                  :key="idx"
                  class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300"
                >
                  {{ label }}
                </span>
                <span v-if="schedule.category_labels.length > 6" class="text-xs text-surface-400">
                  +{{ schedule.category_labels.length - 6 }} more
                </span>
              </div>
            </template>

            <!-- Site details -->
            <template v-else-if="schedule.type === 'site'">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs text-surface-500 font-medium">Sites:</span>
                <span v-if="schedule.sites === 'all'" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300">
                  All Sites
                </span>
                <template v-else>
                  <span 
                    v-for="(site, idx) in (schedule.sites || []).slice(0, 4)" 
                    :key="idx"
                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-300"
                  >
                    {{ site }}
                  </span>
                  <span v-if="schedule.sites?.length > 4" class="text-xs text-surface-400">
                    +{{ schedule.sites.length - 4 }} more
                  </span>
                </template>
              </div>
              <div v-if="schedule.component_labels" class="flex items-center gap-2 flex-wrap">
                <span class="text-xs text-surface-500 font-medium">Components:</span>
                <span 
                  v-for="(label, idx) in schedule.component_labels" 
                  :key="idx"
                  class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300"
                >
                  {{ label }}
                </span>
              </div>
            </template>
          </div>

          <!-- Status Row: Last run outcome + next run time -->
          <div class="mt-3 pt-3 border-t border-surface-100 dark:border-surface-800 flex items-center gap-6 flex-wrap">
            <div class="flex items-center gap-2">
              <span class="text-xs text-surface-500 font-medium">Last run:</span>
              <span
                v-if="schedule.last_run"
                :class="[
                  'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium',
                  schedule.last_status === 'success' ? 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300' :
                  schedule.last_status === 'running' ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300' :
                  schedule.last_status === 'degraded' ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300' :
                  'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300'
                ]"
                :title="schedule.last_message || ''"
              >
                <span class="material-symbols-rounded text-sm">{{
                  schedule.last_status === 'success' ? 'check_circle' :
                  schedule.last_status === 'running' ? 'progress_activity' :
                  schedule.last_status === 'degraded' ? 'warning' : 'error'
                }}</span>
                {{ schedule.last_run }}
                <span class="capitalize">({{ schedule.last_status }})</span>
              </span>
              <span v-else class="text-xs text-surface-400">never (or before tracking was added)</span>
            </div>
            <div v-if="schedule.last_message && schedule.last_status !== 'success'" class="text-xs text-surface-500 italic max-w-md truncate" :title="schedule.last_message">
              {{ schedule.last_message }}
            </div>
            <div class="flex items-center gap-2 ml-auto">
              <span class="text-xs text-surface-500 font-medium">Next run:</span>
              <span v-if="schedule.next_run" class="text-xs font-medium text-surface-700 dark:text-surface-300">{{ schedule.next_run }}</span>
              <span v-else class="text-xs text-surface-400">{{ schedule.enabled ? 'unknown' : 'disabled' }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============================================ -->
    <!-- NAS Storage Tab -->
    <!-- ============================================ -->
    <div v-else-if="activeTab === 'nas'" class="space-y-6">
      <!-- NAS Connection Selector -->
      <div class="card p-6">
        <div class="flex items-center justify-between mb-6">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-primary-600 dark:text-primary-400">hard_drive</span>
            </div>
            <div>
              <h3 class="font-semibold">NAS Remote Storage</h3>
              <p class="text-sm text-surface-500">View backups stored on your NAS connections</p>
            </div>
          </div>
          <RouterLink to="/nas-storage" class="btn-secondary btn-sm">
            <span class="material-symbols-rounded">settings</span>
            Manage NAS
          </RouterLink>
        </div>

        <div v-if="nasLoading" class="flex items-center justify-center py-8">
          <span class="spinner"></span>
        </div>
        
        <div v-else-if="nasConnections.length === 0" class="p-8 text-center">
          <span class="material-symbols-rounded text-4xl text-surface-300 mb-2">hard_drive</span>
          <p class="text-surface-500 mb-4">No NAS connections configured</p>
          <RouterLink to="/nas-storage" class="btn-primary">
            <span class="material-symbols-rounded">add</span>
            Add NAS Connection
          </RouterLink>
        </div>
        
        <div v-else class="space-y-4">
          <div>
            <label class="block text-sm font-medium mb-2">Select NAS Connection</label>
            <select v-model="selectedNasId" @change="fetchRemoteBackups()" class="input">
              <option v-for="nas in nasConnections" :key="nas.id" :value="nas.id">
                {{ nas.name }} ({{ nas.mount_point }})
                <template v-if="nas.is_default"> - Default</template>
              </option>
            </select>
          </div>
          
          <!-- Selected NAS Info -->
          <div v-if="selectedNasId" class="p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
              <div>
                <span class="text-surface-500 block">Driver</span>
                <span class="font-medium uppercase">{{ nasConnections.find(n => n.id === selectedNasId)?.driver }}</span>
              </div>
              <div>
                <span class="text-surface-500 block">Mount Point</span>
                <span class="font-mono text-xs">{{ nasConnections.find(n => n.id === selectedNasId)?.mount_point }}</span>
              </div>
              <div>
                <span class="text-surface-500 block">Status</span>
                <span :class="[
                  'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium',
                  nasConnections.find(n => n.id === selectedNasId)?.status === 'active' 
                    ? 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-300'
                    : 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-300'
                ]">
                  {{ nasConnections.find(n => n.id === selectedNasId)?.status }}
                </span>
              </div>
              <div>
                <span class="text-surface-500 block">VPN</span>
                <span v-if="nasConnections.find(n => n.id === selectedNasId)?.vpn_enabled" class="text-green-600 dark:text-green-400">Enabled</span>
                <span v-else class="text-surface-400">Disabled</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- NAS Backups List with Sub-tabs -->
      <div v-if="nasConnections.length > 0" class="card overflow-hidden">
        <!-- Sub-tabs for NAS backup types -->
        <div class="border-b border-surface-200 dark:border-surface-700">
          <nav class="flex overflow-x-auto px-4 -mb-px">
            <button
              @click="onNasTabChange('config')"
              :class="[
                'flex items-center gap-1.5 px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
                activeNasTab === 'config'
                  ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                  : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
              ]"
            >
              <span class="material-symbols-rounded text-lg">settings</span>
              Config
            </button>
            <button
              @click="onNasTabChange('sites')"
              :class="[
                'flex items-center gap-1.5 px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
                activeNasTab === 'sites'
                  ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                  : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
              ]"
            >
              <span class="material-symbols-rounded text-lg">language</span>
              Sites
            </button>
            <button
              @click="onNasTabChange('emails')"
              :class="[
                'flex items-center gap-1.5 px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
                activeNasTab === 'emails'
                  ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                  : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
              ]"
            >
              <span class="material-symbols-rounded text-lg">mail</span>
              Emails
            </button>
          </nav>
        </div>

        <!-- Header with actions -->
        <div class="p-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
          <h3 class="font-semibold flex items-center gap-2">
            <span class="material-symbols-rounded">folder</span>
            {{ activeNasTab === 'config' ? 'Config' : activeNasTab === 'sites' ? 'Site' : 'Email' }} Backups on NAS
            <span v-if="selectedNasBackups.length > 0" class="text-sm font-normal text-surface-500">
              ({{ selectedNasBackups.length }} selected)
            </span>
          </h3>
          <div class="flex items-center gap-2">
            <button 
              v-if="selectedNasBackups.length > 0"
              @click="deleteNasModal.show = true" 
              class="btn-danger btn-sm"
            >
              <span class="material-symbols-rounded">delete</span>
              Delete Selected
            </button>
            <button @click="fetchRemoteBackups()" class="btn-secondary btn-sm" :disabled="remoteBackupsLoading">
              <span v-if="remoteBackupsLoading" class="spinner-sm"></span>
              <span v-else class="material-symbols-rounded">refresh</span>
              Refresh
            </button>
          </div>
        </div>
        
        <div v-if="remoteBackupsLoading" class="flex items-center justify-center py-8">
          <span class="spinner"></span>
        </div>
        
        <div v-else-if="remoteBackups.length === 0" class="p-8 text-center text-surface-500">
          <span class="material-symbols-rounded text-4xl mb-2">folder_off</span>
          <p>No {{ activeNasTab === 'config' ? 'config' : activeNasTab === 'sites' ? 'site' : 'email' }} backups found on NAS</p>
          <p class="text-sm">Backups will appear here after creating backups with NAS destination</p>
        </div>
        
        <table v-else class="table">
          <thead>
            <tr class="bg-surface-50 dark:bg-surface-800/50">
              <th class="w-12">
                <button 
                  @click="toggleAllNasBackups" 
                  class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors"
                  :class="allNasBackupsSelected 
                    ? 'bg-primary-500 border-primary-500 text-white' 
                    : 'border-surface-300 dark:border-surface-600 hover:border-primary-400'"
                >
                  <span v-if="allNasBackupsSelected" class="material-symbols-rounded text-sm">check</span>
                </button>
              </th>
              <th>File</th>
              <th v-if="activeNasTab === 'sites' || activeNasTab === 'emails'">Domain</th>
              <th v-if="activeNasTab === 'emails'">Accounts</th>
              <th>Size</th>
              <th>Modified</th>
            </tr>
          </thead>
          <tbody>
            <tr 
              v-for="backup in remoteBackups" 
              :key="backup.path"
              :class="selectedNasBackups.includes(backup.path) ? 'bg-primary-50 dark:bg-primary-500/10' : ''"
            >
              <td>
                <button 
                  @click="toggleNasBackupSelection(backup.path)" 
                  class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors"
                  :class="selectedNasBackups.includes(backup.path) 
                    ? 'bg-primary-500 border-primary-500 text-white' 
                    : 'border-surface-300 dark:border-surface-600 hover:border-primary-400'"
                >
                  <span v-if="selectedNasBackups.includes(backup.path)" class="material-symbols-rounded text-sm">check</span>
                </button>
              </td>
              <td>
                <span class="font-mono text-sm">{{ backup.name }}</span>
              </td>
              <td v-if="activeNasTab === 'sites' || activeNasTab === 'emails'">
                <span class="font-medium text-primary-600 dark:text-primary-400">{{ backup.domain || '-' }}</span>
              </td>
              <td v-if="activeNasTab === 'emails'">
                <span v-if="backup.accounts" class="text-sm">{{ backup.accounts }}</span>
                <span v-else class="text-surface-400">-</span>
              </td>
              <td>
                <div class="flex items-center gap-1.5">
                  <span class="text-surface-500">{{ backup.size_human }}</span>
                  <span
                    v-if="backup.split"
                    class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-violet-100 dark:bg-violet-500/15 text-violet-600 dark:text-violet-300"
                    :title="`Large backup stored as ${backup.parts_count} split parts`"
                  >{{ backup.parts_count }} parts</span>
                </div>
              </td>
              <td>
                <span class="text-surface-500">{{ backup.modified_human }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Help Card -->
      <div class="card p-6 bg-surface-50 dark:bg-surface-800/50">
        <h4 class="font-medium flex items-center gap-2 mb-3">
          <span class="material-symbols-rounded text-primary-500">help</span>
          Using NAS for Backups
        </h4>
        <div class="text-sm text-surface-600 dark:text-surface-400 space-y-2">
          <p><strong>Setup:</strong> Configure your NAS connections in the <RouterLink to="/nas-storage" class="text-primary-500 hover:underline">NAS Storage</RouterLink> page.</p>
          <p><strong>Creating Backups:</strong> When creating a backup, select "NAS" or "Both" as the destination to copy backups to your NAS.</p>
          <p><strong>Default NAS:</strong> The default NAS connection is used automatically for backup operations.</p>
        </div>
      </div>
    </div>

    <!-- ============================================ -->
    <!-- Modals -->
    <!-- ============================================ -->

    <!-- Create Backup Modal -->
    <Modal :show="createBackupModal" title="Create Manual Backup" @close="createBackupModal = false">
      <div v-if="categoriesLoading" class="flex items-center justify-center py-8">
        <span class="spinner"></span>
      </div>
      <div v-else class="space-y-4">
        <div class="flex items-center justify-between">
          <p class="text-surface-500 text-sm">
            Select the configuration categories you want to backup:
          </p>
          <button 
            type="button"
            @click="toggleAllCategories"
            class="btn-ghost btn-sm text-primary-500"
          >
            {{ allCategoriesSelected ? 'Deselect All' : 'Select All' }}
          </button>
        </div>

        <div class="space-y-2 max-h-60 overflow-y-auto">
          <div
            v-for="category in categories"
            :key="category.id"
            :class="[
              'flex items-center gap-3 p-3 rounded-xl border-2 transition-all',
              isCategorySelected(category.id)
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                : 'border-surface-200 dark:border-surface-700',
              !category.exists && 'opacity-50'
            ]"
          >
            <div class="flex-1">
              <p class="font-medium">{{ category.label }}</p>
              <p class="text-sm text-surface-500">
                {{ category.exists ? category.size_human : 'Not found' }}
              </p>
            </div>
            <span v-if="category.exists" class="material-symbols-rounded text-green-500">check_circle</span>
            <span v-else class="material-symbols-rounded text-surface-300">cancel</span>
            <Toggle
              :modelValue="isCategorySelected(category.id)"
              @update:modelValue="toggleCategory(category.id)"
              :disabled="!category.exists"
            />
          </div>
        </div>

        <!-- Destination Selector -->
        <div class="pt-4 border-t border-surface-100 dark:border-surface-800">
          <label class="block text-sm font-medium mb-2">Backup Destination</label>
          <div class="grid grid-cols-3 gap-2">
            <button
              type="button"
              @click="backupDestination = 'local'"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                backupDestination === 'local'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
            >
              <span class="material-symbols-rounded text-2xl block mb-1">folder</span>
              <span class="text-sm font-medium">Local</span>
            </button>
            <button
              type="button"
              @click="backupDestination = 'nas'"
              :disabled="nasConnections.length === 0"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                backupDestination === 'nas'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300',
                nasConnections.length === 0 && 'opacity-50 cursor-not-allowed'
              ]"
            >
              <span class="material-symbols-rounded text-2xl block mb-1">hard_drive</span>
              <span class="text-sm font-medium">NAS</span>
            </button>
            <button
              type="button"
              @click="backupDestination = 'both'"
              :disabled="nasConnections.length === 0"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                backupDestination === 'both'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300',
                nasConnections.length === 0 && 'opacity-50 cursor-not-allowed'
              ]"
            >
              <span class="material-symbols-rounded text-2xl block mb-1">sync</span>
              <span class="text-sm font-medium">Both</span>
            </button>
          </div>
          <p v-if="nasConnections.length === 0" class="text-xs text-surface-500 mt-2">
            Configure NAS in NAS Storage page to enable remote backups
          </p>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-surface-100 dark:border-surface-800">
          <button @click="createBackupModal = false" class="btn-secondary">
            Cancel
          </button>
          <button
            @click="createBackup"
            class="btn-primary"
            :disabled="submitting || selectedCategories.length === 0"
          >
            <span v-if="submitting" class="spinner"></span>
            Create Backup
          </button>
        </div>
      </div>
    </Modal>

    <!-- Create Schedule Modal -->
    <Modal :show="createScheduleModal" title="Create Backup Schedule" @close="createScheduleModal = false" size="lg">
      <div v-if="categoriesLoading || sitesLoading" class="flex items-center justify-center py-8">
        <span class="spinner"></span>
      </div>
      <form v-else @submit.prevent="createSchedule" class="space-y-5">
        <!-- Backup Type Selector -->
        <div>
          <label class="block text-sm font-medium mb-3">Backup Type</label>
          <div class="grid grid-cols-2 gap-3">
            <button
              type="button"
              @click="newSchedule.type = 'config'"
              :class="[
                'flex items-center gap-3 p-4 rounded-xl border-2 transition-all text-left',
                newSchedule.type === 'config'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
            >
              <div :class="[
                'w-10 h-10 rounded-lg flex items-center justify-center',
                newSchedule.type === 'config' ? 'bg-primary-500 text-white' : 'bg-surface-100 dark:bg-surface-800'
              ]">
                <span class="material-symbols-rounded">settings</span>
              </div>
              <div>
                <p class="font-medium">Config Backup</p>
                <p class="text-xs text-surface-500">Server configurations</p>
              </div>
            </button>
            <button
              type="button"
              @click="newSchedule.type = 'site'"
              :class="[
                'flex items-center gap-3 p-4 rounded-xl border-2 transition-all text-left',
                newSchedule.type === 'site'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
            >
              <div :class="[
                'w-10 h-10 rounded-lg flex items-center justify-center',
                newSchedule.type === 'site' ? 'bg-primary-500 text-white' : 'bg-surface-100 dark:bg-surface-800'
              ]">
                <span class="material-symbols-rounded">language</span>
              </div>
              <div>
                <p class="font-medium">Site Backup</p>
                <p class="text-xs text-surface-500">Website files & database</p>
              </div>
            </button>
          </div>
        </div>

        <!-- Schedule Settings -->
        <div class="grid grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Frequency</label>
            <select v-model="newSchedule.frequency" class="input">
              <option value="hourly">Hourly</option>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Time</label>
            <input v-model="newSchedule.time" type="time" class="input" />
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Retention</label>
            <div class="relative">
              <input v-model.number="newSchedule.retention" type="number" class="input pr-12" min="1" max="365" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-surface-400 text-sm">days</span>
            </div>
          </div>
        </div>

        <!-- Day of week (weekly only) -->
        <div v-if="newSchedule.frequency === 'weekly'">
          <label class="block text-sm font-medium mb-2">Day of Week</label>
          <select v-model.number="newSchedule.day_of_week" class="input">
            <option v-for="day in dayOfWeekOptions" :key="day.value" :value="day.value">{{ day.label }}</option>
          </select>
        </div>

        <!-- Destination Selector -->
        <div>
          <label class="block text-sm font-medium mb-2">Backup Destination</label>
          <div class="grid grid-cols-3 gap-2">
            <button
              type="button"
              @click="newSchedule.destination = 'local'"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                newSchedule.destination === 'local'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
            >
              <span class="material-symbols-rounded text-xl block mb-1">folder</span>
              <span class="text-xs font-medium">Local</span>
            </button>
            <button
              type="button"
              @click="newSchedule.destination = 'nas'"
              :disabled="nasConnections.length === 0"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                newSchedule.destination === 'nas'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300',
                nasConnections.length === 0 && 'opacity-50 cursor-not-allowed'
              ]"
            >
              <span class="material-symbols-rounded text-xl block mb-1">hard_drive</span>
              <span class="text-xs font-medium">NAS</span>
            </button>
            <button
              type="button"
              @click="newSchedule.destination = 'both'"
              :disabled="nasConnections.length === 0"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                newSchedule.destination === 'both'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300',
                nasConnections.length === 0 && 'opacity-50 cursor-not-allowed'
              ]"
            >
              <span class="material-symbols-rounded text-xl block mb-1">sync</span>
              <span class="text-xs font-medium">Both</span>
            </button>
          </div>
        </div>

        <!-- Config Backup Options -->
        <div v-if="newSchedule.type === 'config'">
          <label class="block text-sm font-medium mb-2">Categories to Backup</label>
          <div class="space-y-2 max-h-48 overflow-y-auto border border-surface-200 dark:border-surface-700 rounded-xl p-2">
            <div
              v-for="category in categories.filter(c => c.exists)"
              :key="category.id"
              :class="[
                'flex items-center justify-between gap-3 p-2 rounded-lg cursor-pointer transition-colors',
                isScheduleCategorySelected(category.id) 
                  ? 'bg-primary-50 dark:bg-primary-500/10' 
                  : 'hover:bg-surface-50 dark:hover:bg-surface-800'
              ]"
              @click="toggleScheduleCategory(category.id)"
            >
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-surface-400 text-lg">{{ 
                  category.id === 'webserver' ? 'dns' :
                  category.id === 'vhosts' ? 'folder' :
                  category.id === 'php' ? 'code' :
                  category.id === 'mysql' ? 'database' :
                  category.id === 'mail' ? 'mail' :
                  category.id === 'dns' ? 'public' :
                  category.id === 'fail2ban' ? 'shield' :
                  category.id === 'firewall' ? 'local_fire_department' :
                  category.id === 'ssl' ? 'lock' :
                  category.id === 'modsec' ? 'security' :
                  category.id === 'cpguard' ? 'verified_user' :
                  category.id === 'cron' ? 'schedule' :
                  category.id === 'ssh' ? 'terminal' :
                  category.id === 'databases' ? 'storage' : 'settings'
                }}</span>
                <span class="text-sm">{{ category.label }}</span>
              </div>
              <Toggle
                :modelValue="isScheduleCategorySelected(category.id)"
                @update:modelValue="toggleScheduleCategory(category.id)"
                @click.stop
              />
            </div>
          </div>
        </div>

        <!-- Site Backup Options -->
        <div v-if="newSchedule.type === 'site'" class="space-y-4">
          <!-- Site Selection -->
          <div>
            <label class="block text-sm font-medium mb-2">Sites to Backup</label>
            
            <!-- All Sites Toggle -->
            <div 
              :class="[
                'flex items-center justify-between p-3 rounded-xl border-2 mb-2 cursor-pointer transition-all',
                newSchedule.allSites
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
              @click="newSchedule.allSites = !newSchedule.allSites; if(newSchedule.allSites) newSchedule.sites = []"
            >
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-primary-500">select_all</span>
                <div>
                  <p class="font-medium">All Sites</p>
                  <p class="text-xs text-surface-500">Backup all {{ sites.length }} sites automatically</p>
                </div>
              </div>
              <Toggle
                :modelValue="newSchedule.allSites"
                @update:modelValue="val => { newSchedule.allSites = val; if(val) newSchedule.sites = [] }"
                @click.stop
              />
            </div>

            <!-- Individual Site Selection -->
            <div v-if="!newSchedule.allSites" class="space-y-2 max-h-40 overflow-y-auto border border-surface-200 dark:border-surface-700 rounded-xl p-2">
              <div
                v-for="site in sites"
                :key="site.domain"
                :class="[
                  'flex items-center justify-between gap-3 p-2 rounded-lg cursor-pointer transition-colors',
                  isScheduleSiteSelected(site.domain) 
                    ? 'bg-primary-50 dark:bg-primary-500/10' 
                    : 'hover:bg-surface-50 dark:hover:bg-surface-800'
                ]"
                @click="toggleScheduleSite(site.domain)"
              >
                <div class="flex items-center gap-2">
                  <span class="material-symbols-rounded text-surface-400">language</span>
                  <span class="text-sm">{{ site.domain }}</span>
                </div>
                <Toggle
                  :modelValue="isScheduleSiteSelected(site.domain)"
                  @update:modelValue="toggleScheduleSite(site.domain)"
                  @click.stop
                />
              </div>
              <div v-if="sites.length === 0" class="py-4 text-center text-surface-400 text-sm">
                No sites found
              </div>
            </div>
          </div>

          <!-- Component Selection -->
          <div>
            <label class="block text-sm font-medium mb-2">What to Backup</label>
            <div class="grid grid-cols-2 gap-2">
              <div
                v-for="component in siteBackupComponents"
                :key="component.id"
                :class="[
                  'flex items-center gap-3 p-3 rounded-xl border-2 cursor-pointer transition-all',
                  isScheduleComponentSelected(component.id)
                    ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                    : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
                ]"
                @click="toggleScheduleComponent(component.id)"
              >
                <span :class="[
                  'material-symbols-rounded',
                  isScheduleComponentSelected(component.id) ? 'text-primary-500' : 'text-surface-400'
                ]">{{ component.icon }}</span>
                <div class="flex-1 min-w-0">
                  <p class="font-medium text-sm">{{ component.label }}</p>
                  <p class="text-xs text-surface-500 truncate">{{ component.description }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="flex justify-end gap-3 pt-4 border-t border-surface-100 dark:border-surface-800">
          <button type="button" @click="createScheduleModal = false" class="btn-secondary">
            Cancel
          </button>
          <button
            type="submit"
            class="btn-primary"
            :disabled="submitting || (newSchedule.type === 'config' && newSchedule.categories.length === 0) || (newSchedule.type === 'site' && !newSchedule.allSites && newSchedule.sites.length === 0)"
          >
            <span v-if="submitting" class="spinner"></span>
            Create Schedule
          </button>
        </div>
      </form>
    </Modal>

    <!-- Edit Schedule Modal -->
    <Modal :show="editScheduleModal.show" title="Edit Backup Schedule" @close="editScheduleModal = { show: false, schedule: null }" size="lg">
      <div v-if="categoriesLoading || sitesLoading" class="flex items-center justify-center py-8">
        <span class="spinner"></span>
      </div>
      <form v-else @submit.prevent="updateSchedule" class="space-y-5">
        <!-- Backup Type Selector -->
        <div>
          <label class="block text-sm font-medium mb-3">Backup Type</label>
          <div class="grid grid-cols-2 gap-3">
            <button
              type="button"
              @click="editSchedule.type = 'config'"
              :class="[
                'flex items-center gap-3 p-4 rounded-xl border-2 transition-all text-left',
                editSchedule.type === 'config'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
            >
              <div :class="[
                'w-10 h-10 rounded-lg flex items-center justify-center',
                editSchedule.type === 'config' ? 'bg-primary-500 text-white' : 'bg-surface-100 dark:bg-surface-800'
              ]">
                <span class="material-symbols-rounded">settings</span>
              </div>
              <div>
                <p class="font-medium">Config Backup</p>
                <p class="text-xs text-surface-500">Server configurations</p>
              </div>
            </button>
            <button
              type="button"
              @click="editSchedule.type = 'site'"
              :class="[
                'flex items-center gap-3 p-4 rounded-xl border-2 transition-all text-left',
                editSchedule.type === 'site'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
            >
              <div :class="[
                'w-10 h-10 rounded-lg flex items-center justify-center',
                editSchedule.type === 'site' ? 'bg-primary-500 text-white' : 'bg-surface-100 dark:bg-surface-800'
              ]">
                <span class="material-symbols-rounded">language</span>
              </div>
              <div>
                <p class="font-medium">Site Backup</p>
                <p class="text-xs text-surface-500">Website files & database</p>
              </div>
            </button>
          </div>
        </div>

        <!-- Schedule Settings -->
        <div class="grid grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Frequency</label>
            <select v-model="editSchedule.frequency" class="input">
              <option value="hourly">Hourly</option>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Time</label>
            <input v-model="editSchedule.time" type="time" class="input" />
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Retention</label>
            <div class="relative">
              <input v-model.number="editSchedule.retention" type="number" class="input pr-12" min="1" max="365" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-surface-400 text-sm">days</span>
            </div>
          </div>
        </div>

        <!-- Day of week (weekly only) -->
        <div v-if="editSchedule.frequency === 'weekly'">
          <label class="block text-sm font-medium mb-2">Day of Week</label>
          <select v-model.number="editSchedule.day_of_week" class="input">
            <option v-for="day in dayOfWeekOptions" :key="day.value" :value="day.value">{{ day.label }}</option>
          </select>
        </div>

        <!-- Destination Selector -->
        <div>
          <label class="block text-sm font-medium mb-2">Backup Destination</label>
          <div class="grid grid-cols-3 gap-2">
            <button
              type="button"
              @click="editSchedule.destination = 'local'"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                editSchedule.destination === 'local'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
            >
              <span class="material-symbols-rounded text-xl block mb-1">folder</span>
              <span class="text-xs font-medium">Local</span>
            </button>
            <button
              type="button"
              @click="editSchedule.destination = 'nas'"
              :disabled="nasConnections.length === 0"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                editSchedule.destination === 'nas'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300',
                nasConnections.length === 0 && 'opacity-50 cursor-not-allowed'
              ]"
            >
              <span class="material-symbols-rounded text-xl block mb-1">hard_drive</span>
              <span class="text-xs font-medium">NAS</span>
            </button>
            <button
              type="button"
              @click="editSchedule.destination = 'both'"
              :disabled="nasConnections.length === 0"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                editSchedule.destination === 'both'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300',
                nasConnections.length === 0 && 'opacity-50 cursor-not-allowed'
              ]"
            >
              <span class="material-symbols-rounded text-xl block mb-1">sync</span>
              <span class="text-xs font-medium">Both</span>
            </button>
          </div>
        </div>

        <!-- Config Backup Options -->
        <div v-if="editSchedule.type === 'config'">
          <label class="block text-sm font-medium mb-2">Categories to Backup</label>
          <div class="space-y-2 max-h-48 overflow-y-auto border border-surface-200 dark:border-surface-700 rounded-xl p-2">
            <div
              v-for="category in categories.filter(c => c.exists)"
              :key="category.id"
              :class="[
                'flex items-center justify-between gap-3 p-2 rounded-lg cursor-pointer transition-colors',
                isEditScheduleCategorySelected(category.id) 
                  ? 'bg-primary-50 dark:bg-primary-500/10' 
                  : 'hover:bg-surface-50 dark:hover:bg-surface-800'
              ]"
              @click="toggleEditScheduleCategory(category.id)"
            >
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-surface-400 text-lg">{{ 
                  category.id === 'webserver' ? 'dns' :
                  category.id === 'vhosts' ? 'folder' :
                  category.id === 'php' ? 'code' :
                  category.id === 'mysql' ? 'database' :
                  category.id === 'mail' ? 'mail' :
                  category.id === 'dns' ? 'public' :
                  category.id === 'fail2ban' ? 'shield' :
                  category.id === 'firewall' ? 'local_fire_department' :
                  category.id === 'ssl' ? 'lock' :
                  category.id === 'modsec' ? 'security' :
                  category.id === 'cpguard' ? 'verified_user' :
                  category.id === 'cron' ? 'schedule' :
                  category.id === 'ssh' ? 'terminal' :
                  category.id === 'databases' ? 'storage' : 'settings'
                }}</span>
                <span class="text-sm">{{ category.label }}</span>
              </div>
              <Toggle
                :modelValue="isEditScheduleCategorySelected(category.id)"
                @update:modelValue="toggleEditScheduleCategory(category.id)"
                @click.stop
              />
            </div>
          </div>
        </div>

        <!-- Site Backup Options -->
        <div v-if="editSchedule.type === 'site'" class="space-y-4">
          <!-- Site Selection -->
          <div>
            <label class="block text-sm font-medium mb-2">Sites to Backup</label>
            
            <!-- All Sites Toggle -->
            <div 
              :class="[
                'flex items-center justify-between p-3 rounded-xl border-2 mb-2 cursor-pointer transition-all',
                editSchedule.allSites
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
              @click="editSchedule.allSites = !editSchedule.allSites; if(editSchedule.allSites) editSchedule.sites = []"
            >
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-primary-500">select_all</span>
                <div>
                  <p class="font-medium">All Sites</p>
                  <p class="text-xs text-surface-500">Backup all {{ sites.length }} sites automatically</p>
                </div>
              </div>
              <Toggle
                :modelValue="editSchedule.allSites"
                @update:modelValue="val => { editSchedule.allSites = val; if(val) editSchedule.sites = [] }"
                @click.stop
              />
            </div>

            <!-- Individual Site Selection -->
            <div v-if="!editSchedule.allSites" class="space-y-2 max-h-40 overflow-y-auto border border-surface-200 dark:border-surface-700 rounded-xl p-2">
              <div
                v-for="site in sites"
                :key="site.domain"
                :class="[
                  'flex items-center justify-between gap-3 p-2 rounded-lg cursor-pointer transition-colors',
                  isEditScheduleSiteSelected(site.domain) 
                    ? 'bg-primary-50 dark:bg-primary-500/10' 
                    : 'hover:bg-surface-50 dark:hover:bg-surface-800'
                ]"
                @click="toggleEditScheduleSite(site.domain)"
              >
                <div class="flex items-center gap-2">
                  <span class="material-symbols-rounded text-surface-400">language</span>
                  <span class="text-sm">{{ site.domain }}</span>
                </div>
                <Toggle
                  :modelValue="isEditScheduleSiteSelected(site.domain)"
                  @update:modelValue="toggleEditScheduleSite(site.domain)"
                  @click.stop
                />
              </div>
              <div v-if="sites.length === 0" class="py-4 text-center text-surface-400 text-sm">
                No sites found
              </div>
            </div>
          </div>

          <!-- Component Selection -->
          <div>
            <label class="block text-sm font-medium mb-2">What to Backup</label>
            <div class="grid grid-cols-2 gap-2">
              <div
                v-for="component in siteBackupComponents"
                :key="component.id"
                :class="[
                  'flex items-center gap-3 p-3 rounded-xl border-2 cursor-pointer transition-all',
                  isEditScheduleComponentSelected(component.id)
                    ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                    : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
                ]"
                @click="toggleEditScheduleComponent(component.id)"
              >
                <span :class="[
                  'material-symbols-rounded',
                  isEditScheduleComponentSelected(component.id) ? 'text-primary-500' : 'text-surface-400'
                ]">{{ component.icon }}</span>
                <div class="flex-1 min-w-0">
                  <p class="font-medium text-sm">{{ component.label }}</p>
                  <p class="text-xs text-surface-500 truncate">{{ component.description }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Enabled Toggle -->
        <div class="flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <div>
            <p class="font-medium">Schedule Enabled</p>
            <p class="text-sm text-surface-500">Run this backup automatically</p>
          </div>
          <Toggle
            :modelValue="editSchedule.enabled"
            @update:modelValue="val => editSchedule.enabled = val"
          />
        </div>

        <!-- Footer -->
        <div class="flex justify-end gap-3 pt-4 border-t border-surface-100 dark:border-surface-800">
          <button type="button" @click="editScheduleModal = { show: false, schedule: null }" class="btn-secondary">
            Cancel
          </button>
          <button
            type="submit"
            class="btn-primary"
            :disabled="submitting || (editSchedule.type === 'config' && editSchedule.categories.length === 0) || (editSchedule.type === 'site' && !editSchedule.allSites && editSchedule.sites.length === 0)"
          >
            <span v-if="submitting" class="spinner"></span>
            Save Changes
          </button>
        </div>
      </form>
    </Modal>

    <!-- Cleanup modal -->
    <ConfirmModal
      :show="cleanupModal.show"
      title="Cleanup Old Backups"
      :message="`Delete all backups older than ${cleanupModal.days} days?`"
      confirm-text="Cleanup"
      :danger="true"
      :loading="submitting"
      require-confirmation="DELETE"
      @confirm="runCleanup"
      @cancel="cleanupModal.show = false"
    />

    <!-- Delete modal -->
    <ConfirmModal
      :show="deleteModal.show"
      title="Delete Backup"
      :message="`Delete backup '${deleteModal.backup?.filename}'?`"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      require-confirmation="DELETE"
      @confirm="deleteBackup"
      @cancel="deleteModal = { show: false, backup: null }"
    />

    <!-- Delete Multiple Backups modal -->
    <ConfirmModal
      :show="deleteMultipleModal.show"
      title="Delete Multiple Backups"
      :message="`Delete ${selectedBackups.length} selected backup(s)? This action cannot be undone.`"
      confirm-text="Delete All"
      :danger="true"
      :loading="deletingBackups"
      @confirm="deleteSelectedBackups"
      @cancel="deleteMultipleModal.show = false"
    />

    <!-- Simple Restore modal (for .bak files) -->
    <ConfirmModal
      :show="restoreModal.show"
      title="Restore Backup"
      :message="getRestoreMessage(restoreModal.backup)"
      confirm-text="Restore"
      :danger="false"
      :loading="submitting"
      require-confirmation="RESTORE"
      @confirm="restoreBackup"
      @cancel="restoreModal = { show: false, backup: null }"
    />

    <!-- Enhanced Config Restore Modal -->
    <Modal 
      :show="restoreConfigModal.show" 
      title="Restore Config Backup" 
      size="lg"
      @close="restoreConfigModal.show = false"
    >
      <div v-if="restoreConfigModal.loading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
        <span class="ml-3 text-surface-500">Analyzing backup contents...</span>
      </div>
      
      <div v-else-if="restoreConfigModal.contents" class="space-y-6">
        <!-- Backup Info -->
        <div class="p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-primary-500 text-2xl">backup</span>
            <div>
              <p class="font-medium">{{ restoreConfigModal.backup?.filename }}</p>
              <p class="text-sm text-surface-500">{{ restoreConfigModal.backup?.date }} - {{ restoreConfigModal.backup?.size_human }}</p>
            </div>
          </div>
        </div>
        
        <!-- Category Selection -->
        <div>
          <div class="flex items-center justify-between mb-3">
            <p class="font-medium">Select Categories to Restore</p>
            <div class="flex gap-2">
              <button 
                @click="restoreConfigModal.selectedCategories = restoreConfigModal.contents.categories?.map(c => c.id) || []"
                class="text-xs text-primary-500 hover:text-primary-600"
              >
                Select All
              </button>
              <span class="text-surface-300">|</span>
              <button 
                @click="restoreConfigModal.selectedCategories = []"
                class="text-xs text-primary-500 hover:text-primary-600"
              >
                Clear All
              </button>
            </div>
          </div>
          
          <div class="space-y-2 max-h-80 overflow-y-auto">
            <label 
              v-for="category in restoreConfigModal.contents.categories" 
              :key="category.id"
              class="flex items-center justify-between p-3 rounded-xl border-2 cursor-pointer transition-all"
              :class="restoreConfigModal.selectedCategories.includes(category.id)
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                : 'border-surface-200 dark:border-surface-700 hover:border-surface-300 dark:hover:border-surface-600'"
            >
              <div class="flex items-center gap-3">
                <input 
                  type="checkbox" 
                  :checked="restoreConfigModal.selectedCategories.includes(category.id)"
                  @change="toggleConfigCategory(category.id)"
                  class="checkbox"
                />
                <div>
                  <p class="font-medium">{{ category.label }}</p>
                  <p class="text-xs text-surface-500">{{ category.items_count }} item(s) - {{ category.total_size_human }}</p>
                </div>
              </div>
              <span v-if="category.items?.length" class="text-xs text-surface-400">
                {{ category.items.map(i => i.original_path?.split('/').pop()).slice(0, 2).join(', ') }}
                {{ category.items.length > 2 ? `+${category.items.length - 2} more` : '' }}
              </span>
            </label>
          </div>
        </div>
        
        <!-- Warning -->
        <div class="p-3 rounded-xl bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 text-sm">
          <div class="flex items-start gap-2">
            <span class="material-symbols-rounded text-amber-500 text-lg">warning</span>
            <div class="text-amber-700 dark:text-amber-300">
              <p><strong>Important:</strong> Restoring configurations will overwrite current settings. A pre-restore backup will be created first.</p>
              <p class="mt-1 text-xs">Services may be automatically reloaded after restore (OpenLiteSpeed, Postfix, Dovecot, Fail2ban, etc.)</p>
            </div>
          </div>
        </div>
        
        <!-- Dry Run Results -->
        <div v-if="restoreConfigModal.dryRunLogs && restoreConfigModal.dryRunLogs.length > 0" class="mt-4">
          <p class="font-medium mb-2 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">science</span>
            Dry Run Results
          </p>
          <div class="bg-surface-900 dark:bg-surface-950 rounded-xl p-4 max-h-64 overflow-y-auto font-mono text-xs">
            <div 
              v-for="(log, idx) in restoreConfigModal.dryRunLogs" 
              :key="idx"
              class="flex items-start gap-2 py-0.5"
            >
              <span class="text-surface-500 shrink-0">{{ log.time }}</span>
              <span 
                class="shrink-0"
                :class="{
                  'text-emerald-400': log.level === 'success',
                  'text-sky-400': log.level === 'info',
                  'text-amber-400': log.level === 'warning',
                  'text-red-400': log.level === 'error'
                }"
              >{{ log.level }}</span>
              <span class="text-surface-300">{{ log.message }}</span>
            </div>
          </div>
        </div>
      </div>
      
      <template #footer>
        <div class="flex justify-end gap-3">
          <button @click="restoreConfigModal.show = false" class="btn-secondary">
            Cancel
          </button>
          <button
            @click="dryRunConfigRestore"
            class="btn-secondary"
            :disabled="restoreConfigModal.dryRunning || submitting || restoreConfigModal.selectedCategories.length === 0"
          >
            <span v-if="restoreConfigModal.dryRunning" class="spinner-sm mr-2"></span>
            <span class="material-symbols-rounded mr-1">science</span>
            Dry Run
          </button>
          <button
            @click="restoreConfigBackup"
            class="btn-primary"
            :disabled="submitting || restoreConfigModal.dryRunning || restoreConfigModal.selectedCategories.length === 0"
          >
            <span v-if="submitting" class="spinner-sm mr-2"></span>
            <span class="material-symbols-rounded mr-1">settings_backup_restore</span>
            Restore {{ restoreConfigModal.selectedCategories.length }} Category(s)
          </button>
        </div>
      </template>
    </Modal>

    <!-- Delete Schedule modal -->
    <ConfirmModal
      :show="deleteScheduleModal.show"
      title="Delete Schedule"
      message="Are you sure you want to delete this backup schedule?"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      @confirm="deleteSchedule"
      @cancel="deleteScheduleModal = { show: false, schedule: null }"
    />

    <!-- Send to NAS Modal (copy or move) -->
    <Modal :show="transferModal.show" title="Send Backup to NAS" @close="transferModal = { show: false, backup: null, mode: 'copy' }">
      <div class="space-y-4">
        <div class="p-3 rounded-xl bg-surface-50 dark:bg-surface-800/50">
          <p class="font-mono text-sm break-all">{{ transferModal.backup?.filename }}</p>
          <p class="text-xs text-surface-500 mt-1">
            {{ transferModal.backup?.size_human }}
            <span v-if="transferModal.backup?.split">
              - split into {{ transferModal.backup?.parts_count }} parts (each part is uploaded and checksum-verified separately)
            </span>
          </p>
        </div>

        <div v-if="nasConnections.length === 0" class="p-3 rounded-xl bg-amber-50 dark:bg-amber-500/10 text-sm text-amber-700 dark:text-amber-300 flex items-start gap-2">
          <span class="material-symbols-rounded text-base mt-0.5">warning</span>
          No NAS connection configured. Set one up in NAS Storage first.
        </div>

        <div class="grid grid-cols-2 gap-2">
          <button
            type="button"
            @click="transferModal.mode = 'copy'"
            :class="[
              'p-4 rounded-xl border-2 text-left transition-all',
              transferModal.mode === 'copy'
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
            ]"
          >
            <span class="material-symbols-rounded text-xl block mb-1 text-cyan-500">file_copy</span>
            <span class="text-sm font-medium block">Copy to NAS</span>
            <span class="text-xs text-surface-500">Keeps the copy on the server. Backup will show as Server + NAS.</span>
          </button>
          <button
            type="button"
            @click="transferModal.mode = 'move'"
            :class="[
              'p-4 rounded-xl border-2 text-left transition-all',
              transferModal.mode === 'move'
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
            ]"
          >
            <span class="material-symbols-rounded text-xl block mb-1 text-purple-500">drive_file_move</span>
            <span class="text-sm font-medium block">Move to NAS</span>
            <span class="text-xs text-surface-500">Frees server disk space. Stays visible in the list with a NAS label and remains restorable.</span>
          </button>
        </div>

        <p class="text-xs text-surface-500">
          The transfer runs in the background - you can keep using the panel and will get a notification when it finishes.
          It is checksum-verified; with Move, the server copy is only removed after the NAS copy is confirmed intact.
        </p>

        <div class="flex justify-end gap-3 pt-2">
          <button class="btn-secondary" @click="transferModal = { show: false, backup: null, mode: 'copy' }" :disabled="transferring">Cancel</button>
          <button class="btn-primary" @click="transferToNas" :disabled="transferring || nasConnections.length === 0">
            <span v-if="transferring" class="spinner-sm"></span>
            <span v-else class="material-symbols-rounded">cloud_upload</span>
            {{ transferring ? 'Transferring...' : (transferModal.mode === 'move' ? 'Move to NAS' : 'Copy to NAS') }}
          </button>
        </div>
      </div>
    </Modal>

    <!-- Site Backup Modal -->
    <Modal :show="siteBackupModal.show" title="Backup Site" @close="siteBackupModal = { show: false, domain: null }">
      <div v-if="sitesLoading" class="flex items-center justify-center py-8">
        <span class="spinner"></span>
      </div>
      <div v-else class="space-y-4">
        <p class="text-surface-500 text-sm">
          Select a site to backup. This will create a full backup including files and databases.
        </p>

        <div class="space-y-2 max-h-80 overflow-y-auto">
          <div
            v-for="site in sites"
            :key="site.domain"
            @click="siteBackupModal.domain = site.domain"
            :class="[
              'flex items-center gap-3 p-3 rounded-xl border-2 cursor-pointer transition-all',
              siteBackupModal.domain === site.domain
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                : 'border-surface-200 dark:border-surface-700 hover:border-surface-300 dark:hover:border-surface-600'
            ]"
          >
            <span class="material-symbols-rounded text-primary-500">language</span>
            <div class="flex-1">
              <p class="font-medium">{{ site.domain }}</p>
              <p class="text-sm text-surface-500">{{ site.size_human || 'Unknown size' }}</p>
            </div>
            <span v-if="siteBackupModal.domain === site.domain" class="material-symbols-rounded text-primary-500">check_circle</span>
          </div>
          
          <div v-if="sites.length === 0" class="py-8 text-center text-surface-400">
            No sites found
          </div>
        </div>

        <!-- Destination Selector -->
        <div>
          <label class="block text-sm font-medium mb-2">Backup Destination</label>
          <div class="grid grid-cols-3 gap-2">
            <button
              type="button"
              @click="siteBackupModal.destination = 'local'"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                siteBackupModal.destination === 'local'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
            >
              <span class="material-symbols-rounded text-xl block mb-1">folder</span>
              <span class="text-xs font-medium">Local</span>
            </button>
            <button
              type="button"
              @click="siteBackupModal.destination = 'nas'"
              :disabled="nasConnections.length === 0"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                siteBackupModal.destination === 'nas'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300',
                nasConnections.length === 0 && 'opacity-50 cursor-not-allowed'
              ]"
            >
              <span class="material-symbols-rounded text-xl block mb-1">hard_drive</span>
              <span class="text-xs font-medium">NAS</span>
            </button>
            <button
              type="button"
              @click="siteBackupModal.destination = 'both'"
              :disabled="nasConnections.length === 0"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                siteBackupModal.destination === 'both'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300',
                nasConnections.length === 0 && 'opacity-50 cursor-not-allowed'
              ]"
            >
              <span class="material-symbols-rounded text-xl block mb-1">sync</span>
              <span class="text-xs font-medium">Both</span>
            </button>
          </div>
        </div>

        <div class="p-3 bg-blue-50 dark:bg-blue-500/10 rounded-xl text-sm text-blue-700 dark:text-blue-300">
          <div class="flex items-start gap-2">
            <span class="material-symbols-rounded text-lg">info</span>
            <div>
              <p class="font-medium">Full site backup includes:</p>
              <ul class="mt-1 space-y-1 text-xs">
                <li>All files in /home/domain/</li>
                <li>Associated databases (auto-detected)</li>
                <li>vHost configuration</li>
                <li>SSL certificates</li>
              </ul>
            </div>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-surface-100 dark:border-surface-800">
          <button @click="siteBackupModal = { show: false, domain: null, destination: 'local' }" class="btn-secondary">
            Cancel
          </button>
          <button
            @click="backupSite(siteBackupModal.domain, siteBackupModal.destination)"
            class="btn-primary"
            :disabled="submitting || !siteBackupModal.domain"
          >
            <span v-if="submitting" class="spinner"></span>
            <span class="material-symbols-rounded" v-else>backup</span>
            Backup Site
          </button>
        </div>
      </div>
    </Modal>

    <!-- Email Backup Modal -->
    <Modal :show="mailBackupModal.show" title="Backup Emails" @close="mailBackupModal = { show: false, domain: null, destination: 'local' }">
      <div v-if="mailDomainsLoading" class="flex items-center justify-center py-8">
        <span class="spinner"></span>
      </div>
      <div v-else class="space-y-4">
        <p class="text-surface-500 text-sm">
          Select a mail domain to backup. This will backup all mailboxes, accounts, and DKIM keys.
        </p>

        <div class="space-y-2 max-h-80 overflow-y-auto">
          <div
            v-for="domain in mailDomains"
            :key="domain.domain"
            @click="mailBackupModal.domain = domain.domain"
            :class="[
              'flex items-center gap-3 p-3 rounded-xl border-2 cursor-pointer transition-all',
              mailBackupModal.domain === domain.domain
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                : 'border-surface-200 dark:border-surface-700 hover:border-surface-300 dark:hover:border-surface-600'
            ]"
          >
            <span class="material-symbols-rounded text-primary-500">mail</span>
            <div class="flex-1">
              <p class="font-medium">{{ domain.domain }}</p>
              <p class="text-sm text-surface-500">{{ domain.accounts }} accounts - {{ domain.size_human }}</p>
            </div>
            <span v-if="mailBackupModal.domain === domain.domain" class="material-symbols-rounded text-primary-500">check_circle</span>
          </div>
          
          <div v-if="mailDomains.length === 0" class="py-8 text-center text-surface-400">
            No mail domains found
          </div>
        </div>

        <!-- Destination Selector -->
        <div>
          <label class="block text-sm font-medium mb-2">Backup Destination</label>
          <div class="grid grid-cols-3 gap-2">
            <button
              type="button"
              @click="mailBackupModal.destination = 'local'"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                mailBackupModal.destination === 'local'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
            >
              <span class="material-symbols-rounded text-xl block mb-1">folder</span>
              <span class="text-xs font-medium">Local</span>
            </button>
            <button
              type="button"
              @click="mailBackupModal.destination = 'nas'"
              :disabled="nasConnections.length === 0"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                mailBackupModal.destination === 'nas'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300',
                nasConnections.length === 0 && 'opacity-50 cursor-not-allowed'
              ]"
            >
              <span class="material-symbols-rounded text-xl block mb-1">hard_drive</span>
              <span class="text-xs font-medium">NAS</span>
            </button>
            <button
              type="button"
              @click="mailBackupModal.destination = 'both'"
              :disabled="nasConnections.length === 0"
              :class="[
                'p-3 rounded-xl border-2 text-center transition-all',
                mailBackupModal.destination === 'both'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300',
                nasConnections.length === 0 && 'opacity-50 cursor-not-allowed'
              ]"
            >
              <span class="material-symbols-rounded text-xl block mb-1">sync</span>
              <span class="text-xs font-medium">Both</span>
            </button>
          </div>
        </div>

        <div class="p-3 bg-blue-50 dark:bg-blue-500/10 rounded-xl text-sm text-blue-700 dark:text-blue-300">
          <div class="flex items-start gap-2">
            <span class="material-symbols-rounded text-lg">info</span>
            <div>
              <p class="font-medium">Email backup includes:</p>
              <ul class="mt-1 space-y-1 text-xs">
                <li>All mailboxes in /home/vmail/domain/</li>
                <li>Mail account credentials (encrypted)</li>
                <li>Mail forwards configuration</li>
                <li>DKIM signing keys</li>
              </ul>
            </div>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-surface-100 dark:border-surface-800">
          <button @click="mailBackupModal = { show: false, domain: null, destination: 'local' }" class="btn-secondary">
            Cancel
          </button>
          <button
            @click="backupMail(mailBackupModal.domain, mailBackupModal.destination)"
            class="btn-primary"
            :disabled="submitting || !mailBackupModal.domain"
          >
            <span v-if="submitting" class="spinner"></span>
            <span class="material-symbols-rounded" v-else>backup</span>
            Backup Emails
          </button>
        </div>
      </div>
    </Modal>

    <!-- Restore Mail Modal -->
    <Modal 
      :show="restoreMailModal.show" 
      title="Restore Email Backup" 
      size="lg"
      @close="restoreMailModal.show = false"
    >
      <div v-if="restoreMailModal.loading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
        <span class="ml-3 text-surface-500">Analyzing backup contents...</span>
      </div>
      
      <div v-else-if="restoreMailModal.contents" class="space-y-6">
        <!-- Backup Info -->
        <div class="p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-primary-500 text-2xl">mail</span>
            <div>
              <p class="font-medium">{{ restoreMailModal.backup?.domain }}</p>
              <p class="text-sm text-surface-500">{{ restoreMailModal.backup?.date }} - {{ restoreMailModal.backup?.size_human }}</p>
            </div>
          </div>
        </div>
        
        <!-- Restore Options -->
        <div class="space-y-4">
          <h4 class="font-medium text-sm text-surface-500 uppercase tracking-wide">Restore Options</h4>
          
          <!-- Mailboxes -->
          <div class="flex items-center justify-between p-3 rounded-xl bg-surface-50 dark:bg-surface-800">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-blue-500">inbox</span>
              <div>
                <p class="font-medium">Mailboxes</p>
                <p class="text-sm text-surface-500">Email content (cur, new, sent, etc.)</p>
              </div>
            </div>
            <Toggle v-model="restoreMailModal.restoreMailboxes" />
          </div>
          
          <!-- Accounts -->
          <div class="flex items-center justify-between p-3 rounded-xl bg-surface-50 dark:bg-surface-800">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-green-500">person</span>
              <div>
                <p class="font-medium">Mail Accounts</p>
                <p class="text-sm text-surface-500">
                  {{ restoreMailModal.contents?.meta?.accounts_count || 0 }} accounts, 
                  {{ restoreMailModal.contents?.meta?.forwards_count || 0 }} forwards
                </p>
              </div>
            </div>
            <Toggle v-model="restoreMailModal.restoreAccounts" />
          </div>
          
          <!-- DKIM -->
          <div class="flex items-center justify-between p-3 rounded-xl bg-surface-50 dark:bg-surface-800">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-purple-500">key</span>
              <div>
                <p class="font-medium">DKIM Keys</p>
                <p class="text-sm text-surface-500">Email signing keys</p>
              </div>
            </div>
            <Toggle v-model="restoreMailModal.restoreDkim" :disabled="!restoreMailModal.contents?.meta?.has_dkim" />
          </div>
        </div>
        
        <!-- Restore Mode -->
        <div>
          <h4 class="font-medium text-sm text-surface-500 uppercase tracking-wide mb-3">Restore Mode</h4>
          <div class="grid grid-cols-2 gap-3">
            <button
              @click="restoreMailModal.mode = 'merge'"
              :class="[
                'p-4 rounded-xl border-2 text-left transition-all',
                restoreMailModal.mode === 'merge'
                  ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
            >
              <span class="material-symbols-rounded text-green-500 mb-2 block">merge</span>
              <p class="font-medium">Merge (Safe)</p>
              <p class="text-xs text-surface-500">Add missing, keep existing</p>
            </button>
            <button
              @click="restoreMailModal.mode = 'replace'"
              :class="[
                'p-4 rounded-xl border-2 text-left transition-all',
                restoreMailModal.mode === 'replace'
                  ? 'border-red-500 bg-red-50 dark:bg-red-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
            >
              <span class="material-symbols-rounded text-red-500 mb-2 block">swap_horiz</span>
              <p class="font-medium">Replace</p>
              <p class="text-xs text-surface-500">Overwrite existing data</p>
            </button>
          </div>
        </div>
        
        <!-- Warning for replace mode -->
        <div v-if="restoreMailModal.mode === 'replace'" class="p-3 bg-red-50 dark:bg-red-500/10 rounded-xl text-sm text-red-700 dark:text-red-300">
          <div class="flex items-start gap-2">
            <span class="material-symbols-rounded text-lg">warning</span>
            <div>
              <p class="font-medium">Warning: Replace mode</p>
              <p class="text-xs mt-1">Existing mailbox data will be backed up before being replaced.</p>
            </div>
          </div>
        </div>
        
        <!-- Dry Run Results -->
        <div v-if="restoreMailModal.dryRunLogs" class="border border-surface-200 dark:border-surface-700 rounded-xl overflow-hidden">
          <div class="p-3 bg-blue-50 dark:bg-blue-500/10 flex items-center gap-2">
            <span class="material-symbols-rounded text-blue-500">science</span>
            <span class="font-medium text-blue-700 dark:text-blue-300">Dry Run Results</span>
          </div>
          <div class="p-3 max-h-64 overflow-y-auto bg-surface-900 text-xs font-mono space-y-1">
            <div 
              v-for="(log, i) in restoreMailModal.dryRunLogs" 
              :key="i"
              :class="[
                'flex gap-2',
                log.level === 'error' ? 'text-red-400' :
                log.level === 'warning' ? 'text-amber-400' :
                log.level === 'success' ? 'text-green-400' :
                'text-surface-300'
              ]"
            >
              <span class="text-surface-500 shrink-0">{{ log.time }}</span>
              <span :class="[
                'uppercase text-xs px-1 rounded shrink-0',
                log.level === 'error' ? 'bg-red-500/20' :
                log.level === 'warning' ? 'bg-amber-500/20' :
                log.level === 'success' ? 'bg-green-500/20' :
                'bg-surface-700'
              ]">{{ log.level }}</span>
              <span>{{ log.message }}</span>
            </div>
          </div>
        </div>
        
        <!-- Actions -->
        <div class="flex justify-end gap-3 pt-4 border-t border-surface-100 dark:border-surface-800">
          <button @click="restoreMailModal.show = false" class="btn-secondary">
            Cancel
          </button>
          <button 
            @click="dryRunMailRestore"
            class="btn-secondary"
            :disabled="restoreMailModal.dryRunning || submitting || (!restoreMailModal.restoreMailboxes && !restoreMailModal.restoreAccounts && !restoreMailModal.restoreDkim)"
          >
            <span v-if="restoreMailModal.dryRunning" class="spinner-sm mr-2"></span>
            <span class="material-symbols-rounded mr-1">science</span>
            Dry Run
          </button>
          <button
            @click="restoreMailBackup"
            :class="restoreMailModal.mode === 'replace' ? 'btn-danger' : 'btn-primary'"
            :disabled="submitting || restoreMailModal.dryRunning || (!restoreMailModal.restoreMailboxes && !restoreMailModal.restoreAccounts && !restoreMailModal.restoreDkim)"
          >
            <span v-if="submitting" class="spinner-sm mr-2"></span>
            <span class="material-symbols-rounded mr-1">settings_backup_restore</span>
            Restore Email
          </button>
        </div>
      </div>
    </Modal>

    <!-- Backup Progress Modal -->
    <Modal 
      :show="backupProgress.show" 
      title="Backup in Progress" 
      :closable="backupProgress.status !== 'running'"
      @close="closeBackupProgress"
    >
      <div class="space-y-6">
        <!-- Header with status -->
        <div class="flex items-center gap-4 p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50">
          <div :class="[
            'w-14 h-14 rounded-xl flex items-center justify-center',
            backupProgress.status === 'running' ? 'bg-blue-100 dark:bg-blue-500/20' :
            backupProgress.status === 'completed' ? 'bg-green-100 dark:bg-green-500/20' :
            backupProgress.status === 'failed' ? 'bg-red-100 dark:bg-red-500/20' :
            'bg-amber-100 dark:bg-amber-500/20'
          ]">
            <span :class="[
              'material-symbols-rounded text-3xl',
              backupProgress.status === 'running' ? 'text-blue-500 animate-spin' :
              backupProgress.status === 'completed' ? 'text-green-500' :
              backupProgress.status === 'failed' ? 'text-red-500' :
              'text-amber-500'
            ]">
              {{ backupProgress.status === 'running' ? 'progress_activity' : 
                 backupProgress.status === 'completed' ? 'check_circle' :
                 backupProgress.status === 'failed' ? 'error' : 'warning' }}
            </span>
          </div>
          <div class="flex-1">
            <h4 class="font-semibold text-lg">{{ backupProgress.domain }}</h4>
            <p class="text-sm text-surface-500 capitalize">
              {{ backupProgress.step.replace('_', ' ') }}
            </p>
          </div>
          <div class="text-right">
            <span class="text-3xl font-bold" :class="[
              backupProgress.status === 'completed' ? 'text-green-600' :
              backupProgress.status === 'failed' ? 'text-red-600' :
              'text-primary-600'
            ]">{{ backupProgress.progress }}%</span>
          </div>
        </div>

        <!-- Step indicators -->
        <div class="flex gap-1">
          <div 
            v-for="step in backupSteps" 
            :key="step.id"
            :class="[
              'flex-1 flex flex-col items-center gap-1 p-2 rounded-lg transition-all',
              getStepStatus(step.id) === 'done' ? 'bg-green-50 dark:bg-green-500/10' :
              getStepStatus(step.id) === 'active' ? 'bg-blue-50 dark:bg-blue-500/10' :
              getStepStatus(step.id) === 'failed' ? 'bg-red-50 dark:bg-red-500/10' :
              'bg-surface-50 dark:bg-surface-800'
            ]"
          >
            <span :class="[
              'material-symbols-rounded text-xl',
              getStepStatus(step.id) === 'done' ? 'text-green-500' :
              getStepStatus(step.id) === 'active' ? 'text-blue-500 animate-pulse' :
              getStepStatus(step.id) === 'failed' ? 'text-red-500' :
              'text-surface-400'
            ]">
              {{ getStepStatus(step.id) === 'done' ? 'check_circle' : 
                 getStepStatus(step.id) === 'failed' ? 'error' : step.icon }}
            </span>
            <span :class="[
              'text-xs font-medium',
              getStepStatus(step.id) === 'done' ? 'text-green-600 dark:text-green-400' :
              getStepStatus(step.id) === 'active' ? 'text-blue-600 dark:text-blue-400' :
              getStepStatus(step.id) === 'failed' ? 'text-red-600 dark:text-red-400' :
              'text-surface-400'
            ]">{{ step.label }}</span>
          </div>
        </div>

        <!-- Progress bar -->
        <div>
          <div class="w-full bg-surface-200 dark:bg-surface-700 rounded-full h-3 overflow-hidden">
            <div 
              :class="[
                'h-3 rounded-full transition-all duration-500',
                backupProgress.status === 'completed' ? 'bg-green-500' :
                backupProgress.status === 'failed' ? 'bg-red-500' :
                'bg-gradient-to-r from-primary-500 to-primary-400'
              ]"
              :style="{ width: `${backupProgress.progress}%` }"
            ></div>
          </div>
        </div>

        <!-- Status message -->
        <div :class="[
          'p-4 rounded-xl text-center',
          backupProgress.status === 'completed' ? 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-300' :
          backupProgress.status === 'failed' ? 'bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-300' :
          'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300'
        ]">
          <p class="font-medium">{{ backupProgress.message }}</p>
          <p v-if="backupProgress.result?.size_human" class="text-sm mt-1 opacity-75">
            Archive size: {{ backupProgress.result.size_human }}
          </p>
        </div>

        <!-- Actions -->
        <div class="flex justify-end gap-3 pt-2">
          <button 
            v-if="backupProgress.status === 'running'"
            class="btn-secondary"
            disabled
          >
            <span class="material-symbols-rounded animate-spin">progress_activity</span>
            Please wait...
          </button>
          <button 
            v-else
            @click="closeBackupProgress"
            class="btn-primary"
          >
            <span class="material-symbols-rounded">check</span>
            Done
          </button>
        </div>
      </div>
    </Modal>

    <!-- Email Backup Progress Modal -->
    <Modal 
      :show="mailBackupProgress.show" 
      title="Email Backup in Progress" 
      :closable="mailBackupProgress.status !== 'running'"
      @close="closeMailBackupProgress"
    >
      <div class="space-y-6">
        <!-- Header with status -->
        <div class="flex items-center gap-4 p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50">
          <div :class="[
            'w-14 h-14 rounded-xl flex items-center justify-center',
            mailBackupProgress.status === 'running' ? 'bg-blue-100 dark:bg-blue-500/20' :
            mailBackupProgress.status === 'completed' ? 'bg-green-100 dark:bg-green-500/20' :
            mailBackupProgress.status === 'failed' ? 'bg-red-100 dark:bg-red-500/20' :
            'bg-amber-100 dark:bg-amber-500/20'
          ]">
            <span :class="[
              'material-symbols-rounded text-3xl',
              mailBackupProgress.status === 'running' ? 'text-blue-500 animate-spin' :
              mailBackupProgress.status === 'completed' ? 'text-green-500' :
              mailBackupProgress.status === 'failed' ? 'text-red-500' :
              'text-amber-500'
            ]">
              {{ mailBackupProgress.status === 'running' ? 'progress_activity' : 
                 mailBackupProgress.status === 'completed' ? 'check_circle' :
                 mailBackupProgress.status === 'failed' ? 'error' : 'warning' }}
            </span>
          </div>
          <div class="flex-1">
            <h4 class="font-semibold text-lg">{{ mailBackupProgress.domain }}</h4>
            <p class="text-sm text-surface-500 capitalize">
              {{ mailBackupProgress.step.replace('_', ' ') }}
            </p>
          </div>
          <div class="text-right">
            <span class="text-3xl font-bold" :class="[
              mailBackupProgress.status === 'completed' ? 'text-green-600' :
              mailBackupProgress.status === 'failed' ? 'text-red-600' :
              'text-primary-600'
            ]">{{ mailBackupProgress.progress }}%</span>
          </div>
        </div>

        <!-- Email Step indicators -->
        <div class="flex gap-1">
          <div 
            v-for="step in mailBackupSteps" 
            :key="step.id"
            :class="[
              'flex-1 flex flex-col items-center gap-1 p-2 rounded-lg transition-all',
              getMailStepStatus(step.id) === 'done' ? 'bg-green-50 dark:bg-green-500/10' :
              getMailStepStatus(step.id) === 'active' ? 'bg-blue-50 dark:bg-blue-500/10' :
              getMailStepStatus(step.id) === 'failed' ? 'bg-red-50 dark:bg-red-500/10' :
              'bg-surface-50 dark:bg-surface-800'
            ]"
          >
            <span :class="[
              'material-symbols-rounded text-xl',
              getMailStepStatus(step.id) === 'done' ? 'text-green-500' :
              getMailStepStatus(step.id) === 'active' ? 'text-blue-500 animate-pulse' :
              getMailStepStatus(step.id) === 'failed' ? 'text-red-500' :
              'text-surface-400'
            ]">
              {{ getMailStepStatus(step.id) === 'done' ? 'check_circle' : 
                 getMailStepStatus(step.id) === 'failed' ? 'error' : step.icon }}
            </span>
            <span :class="[
              'text-xs font-medium',
              getMailStepStatus(step.id) === 'done' ? 'text-green-600 dark:text-green-400' :
              getMailStepStatus(step.id) === 'active' ? 'text-blue-600 dark:text-blue-400' :
              getMailStepStatus(step.id) === 'failed' ? 'text-red-600 dark:text-red-400' :
              'text-surface-400'
            ]">{{ step.label }}</span>
          </div>
        </div>

        <!-- Progress bar -->
        <div>
          <div class="w-full bg-surface-200 dark:bg-surface-700 rounded-full h-3 overflow-hidden">
            <div 
              :class="[
                'h-3 rounded-full transition-all duration-500',
                mailBackupProgress.status === 'completed' ? 'bg-green-500' :
                mailBackupProgress.status === 'failed' ? 'bg-red-500' :
                'bg-gradient-to-r from-primary-500 to-primary-400'
              ]"
              :style="{ width: `${mailBackupProgress.progress}%` }"
            ></div>
          </div>
        </div>

        <!-- Status message -->
        <div :class="[
          'p-4 rounded-xl text-center',
          mailBackupProgress.status === 'completed' ? 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-300' :
          mailBackupProgress.status === 'failed' ? 'bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-300' :
          'bg-blue-50 dark:bg-blue-500/10 text-blue-700 dark:text-blue-300'
        ]">
          <p class="font-medium">{{ mailBackupProgress.message }}</p>
          <p v-if="mailBackupProgress.result?.size_human" class="text-sm mt-1 opacity-75">
            Archive size: {{ mailBackupProgress.result.size_human }}
          </p>
          <p v-if="mailBackupProgress.result?.accounts" class="text-sm mt-1 opacity-75">
            {{ mailBackupProgress.result.accounts }} mail accounts backed up
          </p>
        </div>

        <!-- Actions -->
        <div class="flex justify-end gap-3 pt-2">
          <button 
            v-if="mailBackupProgress.status === 'running'"
            class="btn-secondary"
            disabled
          >
            <span class="material-symbols-rounded animate-spin">progress_activity</span>
            Please wait...
          </button>
          <button 
            v-else
            @click="closeMailBackupProgress"
            class="btn-primary"
          >
            <span class="material-symbols-rounded">check</span>
            Done
          </button>
        </div>
      </div>
    </Modal>

    <!-- Enhanced Restore Site Backup Modal -->
    <Modal 
      :show="restoreSiteModal.show" 
      title="Restore Site Backup" 
      size="lg"
      @close="restoreSiteModal.show = false"
    >
      <div v-if="restoreSiteModal.loading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
        <span class="ml-3 text-surface-500">Analyzing backup contents...</span>
      </div>
      
      <div v-else-if="restoreSiteModal.contents" class="space-y-6">
        <!-- Backup Info -->
        <div class="p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-primary-500 text-2xl">backup</span>
            <div>
              <p class="font-medium">{{ restoreSiteModal.backup?.domain }}</p>
              <p class="text-sm text-surface-500">{{ restoreSiteModal.backup?.date }} - {{ restoreSiteModal.backup?.size_human }}</p>
            </div>
          </div>
        </div>
        
        <!-- Files Selection -->
        <div>
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-3">
              <Toggle v-model="restoreSiteModal.restoreFiles" />
              <span class="flex items-center gap-2 font-medium">
                <span class="material-symbols-rounded text-blue-500">folder</span>
                Restore Files
              </span>
            </div>
            <span class="text-sm text-surface-500">
              {{ restoreSiteModal.contents?.files?.total_size_human || 'N/A' }}
            </span>
          </div>
          
          <div v-if="restoreSiteModal.restoreFiles && restoreSiteModal.contents?.components" class="ml-7 space-y-2">
            <p class="text-xs text-surface-500 mb-2">Select specific components or leave all off for full files restore:</p>
            <div class="grid grid-cols-2 gap-3">
              <div v-if="restoreSiteModal.contents.components.plugins" class="flex items-center justify-between p-2 rounded-lg bg-surface-100 dark:bg-surface-700/50">
                <span class="flex items-center gap-2">
                  <span class="material-symbols-rounded text-sm text-orange-500">extension</span>
                  <span class="text-sm">Plugins</span>
                </span>
                <Toggle :model-value="restoreSiteModal.fileComponents.includes('plugins')" @update:model-value="toggleFileComponent('plugins')" />
              </div>
              <div v-if="restoreSiteModal.contents.components.themes" class="flex items-center justify-between p-2 rounded-lg bg-surface-100 dark:bg-surface-700/50">
                <span class="flex items-center gap-2">
                  <span class="material-symbols-rounded text-sm text-purple-500">palette</span>
                  <span class="text-sm">Themes</span>
                </span>
                <Toggle :model-value="restoreSiteModal.fileComponents.includes('themes')" @update:model-value="toggleFileComponent('themes')" />
              </div>
              <div v-if="restoreSiteModal.contents.components.uploads" class="flex items-center justify-between p-2 rounded-lg bg-surface-100 dark:bg-surface-700/50">
                <span class="flex items-center gap-2">
                  <span class="material-symbols-rounded text-sm text-green-500">image</span>
                  <span class="text-sm">Uploads</span>
                </span>
                <Toggle :model-value="restoreSiteModal.fileComponents.includes('uploads')" @update:model-value="toggleFileComponent('uploads')" />
              </div>
              <div v-if="restoreSiteModal.contents.components.wpcore" class="flex items-center justify-between p-2 rounded-lg bg-surface-100 dark:bg-surface-700/50">
                <span class="flex items-center gap-2">
                  <span class="material-symbols-rounded text-sm text-blue-500">code</span>
                  <span class="text-sm">WP Core</span>
                </span>
                <Toggle :model-value="restoreSiteModal.fileComponents.includes('wpcore')" @update:model-value="toggleFileComponent('wpcore')" />
              </div>
            </div>
          </div>
        </div>
        
        <!-- Database Selection -->
        <div v-if="restoreSiteModal.contents?.databases?.length">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-3">
              <Toggle v-model="restoreSiteModal.restoreDatabase" />
              <span class="flex items-center gap-2 font-medium">
                <span class="material-symbols-rounded text-amber-500">database</span>
                Restore Database(s)
              </span>
            </div>
          </div>
          
          <div v-if="restoreSiteModal.restoreDatabase" class="ml-7 space-y-2">
            <div 
              v-for="db in restoreSiteModal.contents.databases" 
              :key="db.name"
              class="flex items-center justify-between p-2 rounded-lg bg-surface-100 dark:bg-surface-700/50"
            >
              <div class="flex items-center gap-2">
                <span class="text-sm font-mono">{{ db.name }}</span>
                <span class="text-xs text-surface-500">({{ db.size_human }})</span>
              </div>
              <Toggle :model-value="restoreSiteModal.selectedDatabases.includes(db.name)" @update:model-value="toggleDatabase(db.name)" />
            </div>
          </div>
        </div>
        
        <!-- Additional Options -->
        <div class="space-y-3">
          <p class="text-sm font-medium text-surface-600 dark:text-surface-400">Additional Options</p>
          <div class="grid grid-cols-2 gap-3">
            <div v-if="restoreSiteModal.contents?.vhost" class="flex items-center justify-between p-3 rounded-xl bg-surface-100 dark:bg-surface-700/50">
              <span class="flex items-center gap-2">
                <span class="material-symbols-rounded text-sm text-cyan-500">dns</span>
                <span class="text-sm">vHost Config</span>
              </span>
              <Toggle v-model="restoreSiteModal.restoreVhost" />
            </div>
            <div v-if="restoreSiteModal.contents?.ssl" class="flex items-center justify-between p-3 rounded-xl bg-surface-100 dark:bg-surface-700/50">
              <span class="flex items-center gap-2">
                <span class="material-symbols-rounded text-sm text-green-500">lock</span>
                <span class="text-sm">SSL Certs</span>
              </span>
              <Toggle v-model="restoreSiteModal.restoreSsl" />
            </div>
            <div v-if="restoreSiteModal.contents?.dns" class="flex items-center justify-between p-3 rounded-xl bg-surface-100 dark:bg-surface-700/50">
              <span class="flex items-center gap-2">
                <span class="material-symbols-rounded text-sm text-indigo-500">public</span>
                <span class="text-sm">DNS Zone ({{ restoreSiteModal.contents?.dns_records_count || 0 }} records)</span>
              </span>
              <Toggle v-model="restoreSiteModal.restoreDns" />
            </div>
            <div v-if="restoreSiteModal.contents?.mail" class="flex items-center justify-between p-3 rounded-xl bg-surface-100 dark:bg-surface-700/50">
              <span class="flex items-center gap-2">
                <span class="material-symbols-rounded text-sm text-red-500">mail</span>
                <span class="text-sm">Mail ({{ restoreSiteModal.contents?.mail_accounts_count || 0 }} accounts)</span>
              </span>
              <Toggle v-model="restoreSiteModal.restoreMail" />
            </div>
          </div>
        </div>
        
        <!-- Restore Mode -->
        <div>
          <p class="text-sm font-medium text-surface-600 dark:text-surface-400 mb-2">Restore Mode</p>
          <div class="grid grid-cols-2 gap-3">
            <button
              type="button"
              @click="restoreSiteModal.mode = 'merge'"
              :class="[
                'p-3 rounded-xl border-2 text-left transition-all',
                restoreSiteModal.mode === 'merge'
                  ? 'border-green-500 bg-green-50 dark:bg-green-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
            >
              <div class="flex items-center gap-2 mb-1">
                <span class="material-symbols-rounded text-green-500">merge</span>
                <span class="font-medium">Merge (Safe)</span>
              </div>
              <p class="text-xs text-surface-500">Adds/updates files from backup. Keeps files not in backup.</p>
            </button>
            <button
              type="button"
              @click="restoreSiteModal.mode = 'replace'"
              :class="[
                'p-3 rounded-xl border-2 text-left transition-all',
                restoreSiteModal.mode === 'replace'
                  ? 'border-red-500 bg-red-50 dark:bg-red-500/10'
                  : 'border-surface-200 dark:border-surface-700 hover:border-surface-300'
              ]"
            >
              <div class="flex items-center gap-2 mb-1">
                <span class="material-symbols-rounded text-red-500">delete_sweep</span>
                <span class="font-medium">Replace (Destructive)</span>
              </div>
              <p class="text-xs text-surface-500">Exact copy of backup. Deletes files not in backup!</p>
            </button>
          </div>
        </div>
        
        <!-- Warning -->
        <div :class="[
          'p-3 rounded-xl text-sm',
          restoreSiteModal.mode === 'replace' 
            ? 'bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30' 
            : 'bg-blue-50 dark:bg-blue-500/10'
        ]">
          <div class="flex items-start gap-2">
            <span :class="['material-symbols-rounded text-lg', restoreSiteModal.mode === 'replace' ? 'text-red-500' : 'text-blue-500']">
              {{ restoreSiteModal.mode === 'replace' ? 'warning' : 'info' }}
            </span>
            <div>
              <p v-if="restoreSiteModal.mode === 'replace'" class="text-red-700 dark:text-red-300">
                <strong>Warning:</strong> Replace mode will DELETE any files that exist on the server but not in the backup. This includes new plugins, themes, or uploads added after the backup was created.
              </p>
              <p v-else class="text-blue-700 dark:text-blue-300">
                A pre-restore backup will be created before making any changes. You can use it to rollback if needed.
              </p>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Dry Run Logs Display -->
      <div v-if="restoreSiteModal.dryRunLogs?.length > 0" class="mt-6 border-t border-surface-200 dark:border-surface-700 pt-4">
        <div class="flex items-center justify-between mb-3">
          <h4 class="font-medium flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">terminal</span>
            Restore Logs
          </h4>
          <button 
            @click="restoreSiteModal.dryRunLogs = null" 
            class="text-xs text-surface-500 hover:text-surface-700"
          >
            Clear Logs
          </button>
        </div>
        <div class="bg-surface-900 rounded-xl p-4 max-h-64 overflow-y-auto font-mono text-xs space-y-1">
          <div 
            v-for="(log, idx) in restoreSiteModal.dryRunLogs" 
            :key="idx"
            :class="[
              'flex items-start gap-2',
              log.level === 'error' ? 'text-red-400' : '',
              log.level === 'warning' ? 'text-amber-400' : '',
              log.level === 'success' ? 'text-green-400' : '',
              log.level === 'info' ? 'text-surface-400' : ''
            ]"
          >
            <span class="text-surface-600 shrink-0">{{ log.time }}</span>
            <span 
              :class="[
                'shrink-0 w-16 uppercase text-[10px] font-bold px-1 py-0.5 rounded',
                log.level === 'error' ? 'bg-red-500/20 text-red-400' : '',
                log.level === 'warning' ? 'bg-amber-500/20 text-amber-400' : '',
                log.level === 'success' ? 'bg-green-500/20 text-green-400' : '',
                log.level === 'info' ? 'bg-surface-700 text-surface-400' : ''
              ]"
            >
              {{ log.level }}
            </span>
            <span class="break-all">{{ log.message }}</span>
          </div>
        </div>
      </div>
      
      <template #footer>
        <div class="flex justify-between gap-3">
          <button 
            @click="dryRunRestore"
            class="btn-secondary"
            :disabled="restoreSiteModal.dryRunning || submitting || (!restoreSiteModal.restoreFiles && !restoreSiteModal.restoreDatabase && !restoreSiteModal.restoreVhost && !restoreSiteModal.restoreSsl && !restoreSiteModal.restoreDns && !restoreSiteModal.restoreMail)"
          >
            <span v-if="restoreSiteModal.dryRunning" class="spinner-sm mr-2"></span>
            <span class="material-symbols-rounded mr-1">science</span>
            Dry Run
          </button>
          <div class="flex gap-3">
            <button @click="resetRestoreSiteModal" class="btn-secondary">
              Cancel
            </button>
            <button
              @click="restoreSiteBackup"
              :class="restoreSiteModal.mode === 'replace' ? 'btn-danger' : 'btn-primary'"
              :disabled="submitting || restoreSiteModal.dryRunning || (!restoreSiteModal.restoreFiles && !restoreSiteModal.restoreDatabase && !restoreSiteModal.restoreVhost && !restoreSiteModal.restoreSsl && !restoreSiteModal.restoreDns && !restoreSiteModal.restoreMail)"
            >
              <span v-if="submitting" class="spinner-sm mr-2"></span>
              <span class="material-symbols-rounded mr-1">settings_backup_restore</span>
              Restore Selected
            </button>
          </div>
        </div>
      </template>
    </Modal>

    <!-- Delete NAS Backups Modal -->
    <Modal
      :show="deleteNasModal.show"
      title="Delete NAS Backups"
      @close="deleteNasModal.show = false; deleteNasConfirmText = ''"
    >
      <div class="space-y-4">
        <div class="p-4 rounded-lg bg-red-500/10 border border-red-500/30">
          <div class="flex items-start gap-3">
            <span class="material-symbols-rounded text-red-500 text-xl">warning</span>
            <div>
              <p class="font-medium text-red-500">Permanent Deletion</p>
              <p class="text-sm text-surface-400 mt-1">
                You are about to delete <strong class="text-white">{{ selectedNasBackups.length }}</strong> backup(s) from NAS storage.
                This action cannot be undone.
              </p>
            </div>
          </div>
        </div>
        
        <div>
          <label class="block text-sm font-medium mb-2">
            Type <span class="font-mono text-red-400 bg-red-500/10 px-1.5 py-0.5 rounded">DELETE</span> to confirm
          </label>
          <input
            v-model="deleteNasConfirmText"
            type="text"
            class="input"
            placeholder="Type DELETE here"
            autocomplete="off"
          />
        </div>
      </div>
      
      <template #footer>
        <div class="flex justify-end gap-3">
          <button
            @click="deleteNasModal.show = false; deleteNasConfirmText = ''"
            class="btn-secondary"
          >
            Cancel
          </button>
          <button
            @click="deleteSelectedNasBackups"
            class="btn-danger"
            :disabled="deleteNasConfirmText !== 'DELETE' || deletingNasBackups"
          >
            <span v-if="deletingNasBackups" class="spinner-sm mr-2"></span>
            <span class="material-symbols-rounded mr-1">delete_forever</span>
            Delete {{ selectedNasBackups.length }} Backup(s)
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>
