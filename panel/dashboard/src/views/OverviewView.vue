<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { cache, CACHE_KEYS, TTL } from '@/services/cache'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import Toggle from '@/components/Toggle.vue'

import EmailAddonsPanel from '@/components/EmailAddonsPanel.vue'
import AccountAdminMenu from '@/components/AccountAdminMenu.vue'
import MigrationChecklist from '@/components/site-manage/MigrationChecklist.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()
const auth = useAuthStore()

// Cache age for display
const getCacheAge = (key) => cache.getAgeHuman(key)

// Active tab
const activeTab = ref('services')

// Tab scroll indicators
const tabsContainer = ref(null)
const tabsNav = ref(null)
const canScrollLeft = ref(false)
const canScrollRight = ref(false)

const updateScrollIndicators = () => {
  if (!tabsNav.value) return
  const el = tabsNav.value
  canScrollLeft.value = el.scrollLeft > 10
  canScrollRight.value = el.scrollLeft < (el.scrollWidth - el.clientWidth - 10)
}

const scrollToActiveTab = () => {
  nextTick(() => {
    if (!tabsNav.value) return
    const activeBtn = tabsNav.value.querySelector('.tab-btn.active')
    if (activeBtn) {
      activeBtn.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' })
    }
    setTimeout(updateScrollIndicators, 100)
  })
}

const allTabs = [
  { id: 'services', label: 'Services', icon: 'dns' },
  { id: 'addons', label: 'Addons', icon: 'extension', superAdminOnly: true },
  { id: 'databases', label: 'Databases', icon: 'storage' },
  { id: 'ssl', label: 'SSL', icon: 'verified_user' },
  { id: 'mail', label: 'Mail', icon: 'mail' },
  { id: 'migration', label: 'Migration', icon: 'swap_horiz' },
  { id: 'dns', label: 'DNS', icon: 'public' },
  { id: 'wordpress', label: 'WordPress', icon: 'edit_note' },
  { id: 'docker', label: 'Docker', icon: 'deployed_code' },
]

const tabs = computed(() => {
  if (auth.isSuperAdmin) return allTabs
  return allTabs.filter(t => !t.superAdminOnly)
})

// Set active tab from route query
onMounted(() => {
  if (route.query.tab && tabs.value.find(t => t.id === route.query.tab)) {
    activeTab.value = route.query.tab
  }
  // Initialize scroll indicators
  nextTick(() => {
    updateScrollIndicators()
    scrollToActiveTab()
  })
  window.addEventListener('resize', updateScrollIndicators)
})

// Update URL when tab changes
watch(activeTab, (newTab) => {
  router.replace({ query: { tab: newTab } })
})

// ============================================
// VPN & NAS Status (shown in Services tab)
// ============================================
const vpnConnections = ref([])
const vpnLoading = ref(true)
const nasConnections = ref([])
const nasLoading = ref(true)

const fetchVpnConnections = async () => {
  vpnLoading.value = true
  try {
    const response = await api.get('/vpn')
    if (response.data.success) {
      vpnConnections.value = response.data.data.connections || []
    }
  } catch (e) {
    // Silently fail - VPN might not be configured
  } finally {
    vpnLoading.value = false
  }
}

const fetchNasConnections = async () => {
  nasLoading.value = true
  try {
    const response = await api.get('/nas')
    if (response.data.success) {
      nasConnections.value = response.data.data.connections || []
    }
  } catch (e) {
    // Silently fail - NAS might not be configured
  } finally {
    nasLoading.value = false
  }
}

const vpnStats = computed(() => ({
  total: vpnConnections.value.length,
  connected: vpnConnections.value.filter(v => v.status === 'connected').length,
  error: vpnConnections.value.filter(v => v.status === 'error').length,
}))

const nasStats = computed(() => ({
  total: nasConnections.value.length,
  active: nasConnections.value.filter(n => n.status === 'active').length,
  error: nasConnections.value.filter(n => n.status === 'error').length,
}))

// ============================================
// Services Tab State & Logic
// ============================================
const servicesLoading = ref(true)
const services = ref([])
const serviceActionLoading = ref({})
const serviceConfirmModal = ref({
  show: false,
  service: null,
  action: ''
})

// Agent health monitoring
const agentLogs = ref(null)
const agentLogsLoading = ref(false)
const agentLogsExpanded = ref(true)

const agentService = computed(() => {
  return services.value.find(s => s.name === 'vpsadmin-agent')
})

const isAgentCrashed = computed(() => {
  return agentService.value && !agentService.value.active
})

const fetchAgentLogs = async () => {
  agentLogsLoading.value = true
  try {
    const response = await api.get('/services/vpsadmin-agent/logs?lines=100')
    if (response.data.success) {
      agentLogs.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch agent logs', e)
  } finally {
    agentLogsLoading.value = false
  }
}

// Watch for agent crash and auto-fetch logs
watch(isAgentCrashed, (crashed) => {
  if (crashed && !agentLogs.value) {
    fetchAgentLogs()
  }
})

const fetchServices = async (forceRefresh = false) => {
  // Check cache first
  if (!forceRefresh) {
    const cached = cache.get(CACHE_KEYS.SERVICES)
    if (cached) {
      services.value = cached
      servicesLoading.value = false
      return
    }
  }
  
  servicesLoading.value = true
  try {
    const response = await api.get('/services')
    if (response.data.success) {
      services.value = response.data.data.services || []
      cache.set(CACHE_KEYS.SERVICES, services.value, TTL.SHORT) // 5 min for services
    }
  } catch (e) {
    toast.error('Failed to load services')
  } finally {
    servicesLoading.value = false
  }
}

const performServiceAction = async (service, action) => {
  serviceActionLoading.value[service.name] = action
  
  try {
    const response = await api.post(`/services/${service.name}/${action}`)
    if (response.data.success) {
      toast.success(response.data.message || `Service ${action}ed`)
      await fetchServices()
    } else {
      toast.error(response.data.error || `Failed to ${action} service`)
    }
  } catch (e) {
    toast.error(e.response?.data?.error || `Failed to ${action} service`)
  } finally {
    serviceActionLoading.value[service.name] = null
    serviceConfirmModal.value.show = false
  }
}

const showServiceConfirm = (service, action) => {
  serviceConfirmModal.value = {
    show: true,
    service,
    action,
    message: `Are you sure you want to ${action} ${service.name}?`
  }
}

const handleServiceConfirm = () => {
  if (serviceConfirmModal.value.service && serviceConfirmModal.value.action) {
    performServiceAction(serviceConfirmModal.value.service, serviceConfirmModal.value.action)
  }
}

// ============================================
// Databases Tab State & Logic
// ============================================
const dbLoading = ref(true)
const databases = ref([])
const dbCreateModal = ref(false)
const dbDeleteModal = ref({ show: false, db: null })
const dbSubmitting = ref(false)
const dbShowSystem = ref(false)
const dbSearchQuery = ref('')

const newDb = ref({
  name: '',
  user: '',
  password: ''
})

const filteredDatabases = computed(() => {
  let result = databases.value
  
  if (!dbShowSystem.value) {
    result = result.filter(db => !db.is_system)
  }
  
  if (dbSearchQuery.value) {
    const query = dbSearchQuery.value.toLowerCase()
    result = result.filter(db => 
      db.name.toLowerCase().includes(query) ||
      db.linked_site?.toLowerCase().includes(query) ||
      db.users?.some(u => u.User.toLowerCase().includes(query))
    )
  }
  
  return result
})

const dbTotalSize = computed(() => {
  return databases.value
    .filter(db => !db.is_system)
    .reduce((sum, db) => sum + (db.size || 0), 0)
})

const formatSize = (bytes) => {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  let i = 0
  while (bytes >= 1024 && i < units.length - 1) {
    bytes /= 1024
    i++
  }
  return `${bytes.toFixed(1)} ${units[i]}`
}

// Safe clipboard function with fallback for non-HTTPS contexts
const copyToClipboard = async (text, successMessage = 'Copied to clipboard') => {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
    } else {
      // Fallback for non-HTTPS or older browsers
      const textArea = document.createElement('textarea')
      textArea.value = text
      textArea.style.position = 'fixed'
      textArea.style.left = '-9999px'
      document.body.appendChild(textArea)
      textArea.select()
      document.execCommand('copy')
      document.body.removeChild(textArea)
    }
    toast.success(successMessage)
  } catch (e) {
    toast.error('Failed to copy to clipboard')
  }
}

// phpMyAdmin access - generates secure token via API
const pmaLoading = ref({})

const openPhpMyAdmin = async (dbName) => {
  if (pmaLoading.value[dbName]) return
  
  pmaLoading.value[dbName] = true
  try {
    const response = await api.post('/phpmyadmin/token', { database: dbName })
    if (response.data.success && response.data.data.url) {
      window.open(response.data.data.url, '_blank')
    } else {
      throw new Error(response.data.error || 'Failed to generate access token')
    }
  } catch (error) {
    console.error('phpMyAdmin access error:', error)
    // Fallback: open phpMyAdmin directly (will require manual login)
    window.open(`https://panel.devcon1.hu/phpmyadmin/?db=${encodeURIComponent(dbName)}`, '_blank')
  } finally {
    pmaLoading.value[dbName] = false
  }
}

const fetchDatabases = async (forceRefresh = false) => {
  // Check cache first
  if (!forceRefresh) {
    const cached = cache.get(CACHE_KEYS.DATABASES)
    if (cached) {
      databases.value = cached
      dbLoading.value = false
      return
    }
  }
  
  dbLoading.value = true
  try {
    const response = await api.get('/databases')
    if (response.data.success) {
      databases.value = response.data.data.databases || []
      cache.set(CACHE_KEYS.DATABASES, databases.value, TTL.LONG)
    }
  } catch (e) {
    toast.error('Failed to load databases')
  } finally {
    dbLoading.value = false
  }
}

const generateDbPassword = () => {
  const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*'
  let password = ''
  for (let i = 0; i < 16; i++) {
    password += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  newDb.value.password = password
}

const createDatabase = async () => {
  dbSubmitting.value = true
  try {
    const response = await api.post('/databases', newDb.value)
    if (response.data.success) {
      toast.success('Database created')
      if (response.data.data.password) {
        toast.info(`Password: ${response.data.data.password}`, 0)
      }
      dbCreateModal.value = false
      newDb.value = { name: '', user: '', password: '' }
      // Force refresh so the new DB shows right away (bypass client cache).
      await fetchDatabases(true)
    } else {
      toast.error(response.data.error || 'Failed to create database')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create database')
  } finally {
    dbSubmitting.value = false
  }
}

const deleteDatabase = async () => {
  if (!dbDeleteModal.value.db) return
  
  dbSubmitting.value = true
  try {
    const response = await api.delete(`/databases/${dbDeleteModal.value.db.name}`)
    if (response.data.success) {
      toast.success('Database deleted')
      dbDeleteModal.value = { show: false, db: null }
      // Force a real re-fetch (and cache refresh) so the just-deleted DB
      // disappears immediately instead of lingering until the client
      // cache TTL expires — the "Updated X min ago" stale-list bug.
      await fetchDatabases(true)
    } else {
      toast.error(response.data.error || 'Failed to delete database')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete database')
  } finally {
    dbSubmitting.value = false
  }
}

// ============================================
// SSL Tab State & Logic
// ============================================
const sslLoading = ref(true)
const certificates = ref([])
const sslIssueModal = ref({ show: false, domain: '', force: false })
const sslDeleteModal = ref({ show: false, cert: null })
const sslPreflightResult = ref(null)
const sslSubmitting = ref(false)

const sslSearchQuery = ref('')
const sslFilterType = ref('all')
const sslSortBy = ref('expiry')

const filteredCertificates = computed(() => {
  let result = [...certificates.value]
  
  if (sslSearchQuery.value) {
    const query = sslSearchQuery.value.toLowerCase()
    result = result.filter(cert => 
      cert.domain.toLowerCase().includes(query) ||
      cert.issuer?.toLowerCase().includes(query)
    )
  }
  
  if (sslFilterType.value === 'mail') {
    // Show certs that cover mail subdomain (either as main domain or in SANs)
    result = result.filter(cert => 
      cert.domain.startsWith('mail.') || 
      cert.sans?.some(san => san.startsWith('mail.'))
    )
  } else if (sslFilterType.value === 'sites') {
    // Show site certs (exclude standalone mail.* certs)
    result = result.filter(cert => !cert.domain.startsWith('mail.'))
  } else if (sslFilterType.value === 'selfsigned') {
    result = result.filter(cert => cert.is_self_signed)
  }
  
  if (sslSortBy.value === 'expiry') {
    result.sort((a, b) => (a.days_remaining || 0) - (b.days_remaining || 0))
  } else if (sslSortBy.value === 'name') {
    result.sort((a, b) => a.domain.localeCompare(b.domain))
  } else if (sslSortBy.value === 'days') {
    result.sort((a, b) => (b.days_remaining || 0) - (a.days_remaining || 0))
  }
  
  return result
})

const sslStats = computed(() => ({
  total: certificates.value.length,
  valid: certificates.value.filter(c => !c.is_expired && !c.is_self_signed).length,
  expiring: certificates.value.filter(c => c.days_remaining < 30 && !c.is_expired).length,
  expired: certificates.value.filter(c => c.is_expired).length,
  selfSigned: certificates.value.filter(c => c.is_self_signed).length
}))

const fetchCertificates = async (forceRefresh = false) => {
  // Check cache first
  if (!forceRefresh) {
    const cached = cache.get(CACHE_KEYS.SSL)
    if (cached) {
      certificates.value = cached
      sslLoading.value = false
      return
    }
  }
  
  sslLoading.value = true
  try {
    const response = await api.get('/ssl', { params: forceRefresh ? { refresh: '1' } : {} })
    if (response.data.success) {
      certificates.value = response.data.data.certificates || []
      cache.set(CACHE_KEYS.SSL, certificates.value, TTL.LONG)
    }
  } catch (e) {
    toast.error('Failed to load certificates')
  } finally {
    sslLoading.value = false
  }
}

const runPreflight = async () => {
  sslSubmitting.value = true
  sslPreflightResult.value = null
  
  try {
    const response = await api.post(`/ssl/${sslIssueModal.value.domain}/preflight`)
    if (response.data.success) {
      sslPreflightResult.value = response.data.data
    } else {
      toast.error(response.data.error || 'Preflight check failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Preflight check failed')
  } finally {
    sslSubmitting.value = false
  }
}

const issueCertificate = async () => {
  sslSubmitting.value = true
  
  try {
    const response = await api.post(`/ssl/${sslIssueModal.value.domain}/issue`, {
      force: sslIssueModal.value.force
    })
    if (response.data.success) {
      toast.success('Certificate issued successfully')
      sslIssueModal.value = { show: false, domain: '', force: false }
      sslPreflightResult.value = null
      cache.invalidate(CACHE_KEYS.SSL)
      cache.invalidate(CACHE_KEYS.SITES)
      await fetchCertificates(true)
    } else {
      toast.error(response.data.error || 'Failed to issue certificate')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to issue certificate')
  } finally {
    sslSubmitting.value = false
  }
}

const sslRenewing = ref({})

const renewCert = async (domain) => {
  sslRenewing.value[domain] = true

  try {
    const response = await api.post(`/ssl/${domain}/issue`, { force: true })
    if (response.data.success) {
      toast.success(`Certificate renewed for ${domain}`)
      cache.invalidate(CACHE_KEYS.SSL)
      cache.invalidate(CACHE_KEYS.SITES)
      fetchCertificates(true)
    } else {
      toast.error(response.data.error || `Failed to renew ${domain}`)
    }
  } catch (e) {
    const msg = e.response?.data?.error || e.message || ''
    if (msg.includes('timed out')) {
      toast.error(`Renewal for ${domain} is taking longer than expected. The certificate may still be issued - check back in a moment.`)
    } else {
      toast.error(msg || `Failed to renew ${domain}`)
    }
  } finally {
    sslRenewing.value[domain] = false
  }
}

const renewAllCerts = async () => {
  sslSubmitting.value = true
  
  try {
    const response = await api.post('/ssl/renew')
    if (response.data.success) {
      toast.success('Certificates renewed')
      cache.invalidate(CACHE_KEYS.SSL)
      cache.invalidate(CACHE_KEYS.SITES)
      await fetchCertificates(true)
    } else {
      toast.error(response.data.error || 'Renewal failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Renewal failed')
  } finally {
    sslSubmitting.value = false
  }
}

const getCertStatus = (cert) => {
  if (cert.is_expired) return 'expired'
  if (cert.is_self_signed) return 'warning'
  if (cert.days_remaining < 30) return 'expiring'
  return 'valid'
}

const getStatusClass = (cert) => {
  const status = getCertStatus(cert)
  if (status === 'valid') return 'text-green-600 dark:text-green-400'
  if (status === 'expired') return 'text-red-600 dark:text-red-400'
  return 'text-amber-600 dark:text-amber-400'
}

const openSslIssueModal = (domain = '') => {
  sslIssueModal.value = { show: true, domain, force: false }
  sslPreflightResult.value = null
}

const deleteCertificate = async () => {
  if (!sslDeleteModal.value.cert) return
  
  sslSubmitting.value = true
  try {
    const response = await api.delete(`/ssl/${sslDeleteModal.value.cert.domain}`)
    if (response.data.success) {
      toast.success('Certificate deleted')
      sslDeleteModal.value = { show: false, cert: null }
      cache.invalidate(CACHE_KEYS.SSL)
      cache.invalidate(CACHE_KEYS.SITES)
      await fetchCertificates(true)
    } else {
      toast.error(response.data.error || 'Failed to delete certificate')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete certificate')
  } finally {
    sslSubmitting.value = false
  }
}

// SSL Health Check
const sslHealth = ref(null)
const sslHealthLoading = ref(false)
const sslHealthFixing = ref({})

const fetchSslHealth = async () => {
  sslHealthLoading.value = true
  try {
    const response = await api.get('/ssl/health')
    if (response.data.success) {
      sslHealth.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch SSL health:', e)
  } finally {
    sslHealthLoading.value = false
  }
}

const fixSslIssue = async (issue) => {
  sslHealthFixing.value[issue.id] = true
  try {
    const response = await api.post('/ssl/health/fix', { issue_id: issue.id })
    if (response.data.success) {
      toast.success(`Fixed: ${issue.message}`)
      cache.invalidate(CACHE_KEYS.SSL)
      await fetchSslHealth()
      await fetchCertificates(true)
    } else {
      toast.error(response.data.error || 'Failed to fix issue')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to fix issue')
  } finally {
    sslHealthFixing.value[issue.id] = false
  }
}

const fixAllSslIssues = async () => {
  sslHealthLoading.value = true
  try {
    const response = await api.post('/ssl/health/fix', { fix_all: true })
    if (response.data.success) {
      toast.success(`Fixed ${response.data.data.fixed_count} issue(s)`)
      cache.invalidate(CACHE_KEYS.SSL)
      await fetchSslHealth()
      await fetchCertificates(true)
    } else {
      toast.error(response.data.error || 'Failed to fix issues')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to fix issues')
  } finally {
    sslHealthLoading.value = false
  }
}

const getSeverityClass = (severity) => {
  if (severity === 'error') return 'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-400'
  if (severity === 'warning') return 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-400'
  return 'bg-blue-100 text-blue-800 dark:bg-blue-500/20 dark:text-blue-400'
}

const getSeverityIcon = (severity) => {
  if (severity === 'error') return 'error'
  if (severity === 'warning') return 'warning'
  return 'info'
}

// ============================================
// Mail Tab State & Logic
// ============================================
const mailLoading = ref(true)
const mailStatus = ref(null)
const mailDomains = ref([])
const mailAccounts = ref([])
const mailForwards = ref([])
const mailCreateModal = ref(false)
const mailDeleteModal = ref({ show: false, type: null, item: null })
const mailResetPasswordModal = ref({ show: false, account: null })
const mailRedirectModal = ref({ 
  show: false, 
  source: '', 
  destinations: [], // existing forwards
  newDestination: '', 
  keepCopy: false,
  loading: false 
})
const mailSubmitting = ref(false)
const forcePwChangePending = ref(null)
const suspendPending = ref(null)
const mailSuspendModal = ref({ show: false, account: null, reason: '', busy: false })
const mailSearchQuery = ref('')
const mailSelectedDomain = ref('all')
const mailShowSettings = ref(false)

const newMailAccount = ref({ email: '', domain: '', password: '' })
const newMailPassword = ref('')
// Password fields start masked; the eye toggle reveals them.
const showMailCreatePassword = ref(false)
const showMailResetPassword = ref(false)

// IMAP Migration State
const mailMigratePopup = ref(false)
const mailMigrateSingleModal = ref(false)
const mailMigrateMultipleModal = ref(false)
const mailMigrateStatusSection = ref(false)
const mailMigrateSubmitting = ref(false)
const mailMigrations = ref([])
const mailMigrationsLoading = ref(false)
const mailMigrateMultipleTab = ref('paste') // 'paste' or 'upload'
// Preflight (test connection) results for the Start Migration modals.
const migratePreflight = ref({ running: false, ran: false, allOk: false, results: [] })

// Contacts (VCF/CSV) + Calendar (ICS) migration — pushed to FlowOne
// via the Panel /migration/dav-import proxy. Same admin-driven model as
// the mail migration above.
const davImports = ref([])
const davImportsLoading = ref(false)
const davImportsClearing = ref(false)
const davSubmitting = ref(false)
// Reverse direction: pull a user's contacts/calendar back out of FlowOne
// (.vcf / .ics) via the Panel /migration/dav-export proxy (own card below
// the import card).
const davExportEmail = ref('')
const davExporting = ref('') // '' | 'contacts' | 'calendar' | 'both'
// All known mailboxes (loaded with the migration tab) for the export dropdown.
const davAccountEmails = computed(() =>
  [...new Set(mailAccounts.value.map((a) => String(a.email || '').toLowerCase()).filter(Boolean))].sort()
)
const davForm = ref({
  userEmail: '',
  type: 'contacts', // 'contacts' | 'calendar'
  fileName: '',
  data: '',
  format: '', // auto-detected from the file extension
})

// Batch import: many per-user files at once. Each filename (without extension)
// becomes the mailbox local part (robert.fekete.csv -> robert.fekete@<domain>);
// a filename that is already a full email is used as-is (mixed domains). Type is
// routed by extension (.ics -> calendar, .vcf/.csv -> contacts).
const davMode = ref('single') // 'single' | 'batch'
const davBatchDomain = ref('')
// row: { file, fileName, email, type, format, status, error, counts }
//   status: 'ready' | 'importing' | 'completed' | 'failed' | 'error'
const davBatchRows = ref([])
const davBatchRunning = ref(false)
const davBatchProcessed = computed(() =>
  davBatchRows.value.filter((r) => ['completed', 'failed', 'error'].includes(r.status)).length)
const davBatchReadyCount = computed(() =>
  davBatchRows.value.filter((r) => r.status === 'ready' || r.status === 'failed').length)
const davBatchErrorCount = computed(() =>
  davBatchRows.value.filter((r) => r.status === 'error' || r.status === 'failed').length)

const newMigration = ref({
  sourceHost: '',
  sourceSsl: true,
  destHost: '',
  destSsl: true,
  // Single migration - source email + optional distinct destination email.
  // Destination defaults to the source address (server-to-server move); set it
  // when the new mailbox differs, e.g. Gmail -> a domain mailbox.
  email: '',
  destEmail: '',
  oldPassword: '',
  newPassword: '',
  // Multiple migration
  accountsText: '',
  accountsFile: null,
  // 'initial' first full copy, 'delta' periodic top-up, 'final' cutover sweep.
  mode: 'initial',
  // Provision destination mailbox(es) before syncing (idempotent — skips
  // ones that already exist) so imapsync has somewhere to write.
  createMailbox: true,
})

const serverHostname = ref('')

// Known consumer/business providers whose IMAP host can't be guessed from the
// domain (Gmail's MX is google.com, etc.). For everything else we fall back to
// mail.<domain>, the de-facto convention for hosted/cPanel mail. The source
// host is only ever a *suggestion* — the user can always override it.
const IMAP_HOST_MAP = {
  'gmail.com': 'imap.gmail.com',
  'googlemail.com': 'imap.gmail.com',
  'outlook.com': 'outlook.office365.com',
  'hotmail.com': 'outlook.office365.com',
  'live.com': 'outlook.office365.com',
  'msn.com': 'outlook.office365.com',
  'office365.com': 'outlook.office365.com',
  'yahoo.com': 'imap.mail.yahoo.com',
  'yahoo.co.uk': 'imap.mail.yahoo.com',
  'ymail.com': 'imap.mail.yahoo.com',
  'aol.com': 'imap.aol.com',
  'icloud.com': 'imap.mail.me.com',
  'me.com': 'imap.mail.me.com',
  'mac.com': 'imap.mail.me.com',
  'zoho.com': 'imap.zoho.com',
  'gmx.com': 'imap.gmx.com',
  'gmx.net': 'imap.gmx.net',
}

const detectImapHost = (email) => {
  const domain = String(email || '').split('@')[1]?.trim().toLowerCase()
  if (!domain || !domain.includes('.')) return ''
  return IMAP_HOST_MAP[domain] || `mail.${domain}`
}

// Tracks the last host WE auto-filled so we can keep it in sync with the email
// until the user edits the field themselves, after which we stop touching it.
const autoSourceHost = ref('')
watch(() => newMigration.value.email, (email) => {
  const guess = detectImapHost(email)
  if (!guess) return
  // Only overwrite when the field is empty or still holds our last guess —
  // never clobber a host the user typed by hand.
  if (!newMigration.value.sourceHost || newMigration.value.sourceHost === autoSourceHost.value) {
    newMigration.value.sourceHost = guess
    autoSourceHost.value = guess
  }
})

// Toggles the inline explanation of the initial/delta/final sync phases.
const showSyncHelp = ref(false)

// Recent migrations now come from the full list endpoint, so "active"
// means anything still running/pending within that recent window.
const runningCount = computed(() =>
  mailMigrations.value.filter((m) => m.status === 'running' || m.status === 'pending').length)

// Terminal migrations that can be cleaned up from the list.
const TERMINAL_MIGRATION_STATUSES = ['completed', 'failed', 'cancelled']
const finishedMigrationsCount = computed(() =>
  mailMigrations.value.filter((m) => TERMINAL_MIGRATION_STATUSES.includes(m.status)).length)
const migrationDeleting = ref(new Set())
const migrationClearing = ref(false)

// Mail server hostname shown in the "Mail Server Settings" card.
// - When a single domain is selected, use that domain's mail host
//   (mail.<domain>), which is what its MX / A record points at.
// - For "All Domains", fall back to the server's canonical Postfix
//   hostname (mail.status -> postconf myhostname), then the imapsync
//   server hostname, then a generic placeholder as a last resort.
const mailServerHost = computed(() => {
  if (mailSelectedDomain.value && mailSelectedDomain.value !== 'all') {
    return `mail.${mailSelectedDomain.value}`
  }
  return mailStatus.value?.hostname || serverHostname.value || 'mail.yourdomain.com'
})

const mailSortColumn = ref('email')
const mailSortOrder = ref('asc')

const toggleMailSort = (column) => {
  if (mailSortColumn.value === column) {
    mailSortOrder.value = mailSortOrder.value === 'asc' ? 'desc' : 'asc'
  } else {
    mailSortColumn.value = column
    mailSortOrder.value = 'asc'
  }
}

const forwardsBySource = computed(() => {
  const map = {}
  for (const fwd of mailForwards.value) {
    if (!map[fwd.source]) {
      map[fwd.source] = []
    }
    map[fwd.source].push(fwd.destination)
  }
  return map
})

const filteredMailAccounts = computed(() => {
  let result = [...mailAccounts.value]
  
  if (mailSelectedDomain.value !== 'all') {
    result = result.filter(a => a.domain === mailSelectedDomain.value)
  }
  
  if (mailSearchQuery.value) {
    const query = mailSearchQuery.value.toLowerCase()
    result = result.filter(a => 
      a.email.toLowerCase().includes(query) ||
      forwardsBySource.value[a.email]?.some(d => d.toLowerCase().includes(query))
    )
  }
  
  result.sort((a, b) => {
    let aVal, bVal
    
    switch (mailSortColumn.value) {
      case 'email':
        aVal = a.email || ''
        bVal = b.email || ''
        break
      case 'size':
        aVal = a.size || 0
        bVal = b.size || 0
        break
      default:
        aVal = a.email || ''
        bVal = b.email || ''
    }
    
    if (typeof aVal === 'string') {
      return mailSortOrder.value === 'asc' 
        ? aVal.localeCompare(bVal) 
        : bVal.localeCompare(aVal)
    }
    return mailSortOrder.value === 'asc' ? aVal - bVal : bVal - aVal
  })
  
  return result
})

const mailDomainList = computed(() => {
  const domains = [...new Set(mailAccounts.value.map(a => a.domain))]
  return domains.sort()
})

const emailAppStats = computed(() => {
  const users = mailAccounts.value.filter(a => a.uses_email_app).length
  const totalDriveUsed = mailAccounts.value.reduce((sum, a) => sum + (a.drive_used || 0), 0)
  const totalAuxAccounts = mailAccounts.value.reduce((sum, a) => sum + (a.aux_accounts || 0), 0)
  const totalOauthAccounts = mailAccounts.value.reduce((sum, a) => sum + (a.oauth_accounts || 0), 0)
  const totalLinkedAccounts = totalAuxAccounts + totalOauthAccounts
  
  // Format drive size
  let driveUsedHuman = '0 B'
  if (totalDriveUsed > 0) {
    const units = ['B', 'KB', 'MB', 'GB']
    let size = totalDriveUsed
    let i = 0
    while (size >= 1024 && i < units.length - 1) {
      size /= 1024
      i++
    }
    driveUsedHuman = `${size.toFixed(1)} ${units[i]}`
  }
  
  return {
    users,
    totalDriveUsed,
    driveUsedHuman,
    totalAuxAccounts,
    totalOauthAccounts,
    totalLinkedAccounts
  }
})

const fetchMailStatus = async () => {
  try {
    const response = await api.get('/mail/status')
    if (response.data.success) {
      mailStatus.value = response.data.data
    }
  } catch (e) {
    console.error(e)
  }
}

const fetchMailDomains = async (forceRefresh = false) => {
  if (!forceRefresh) {
    const cached = cache.get(CACHE_KEYS.MAIL_DOMAINS)
    if (cached) {
      mailDomains.value = cached
      return
    }
  }
  
  try {
    const response = await api.get('/mail/domains')
    if (response.data.success) {
      mailDomains.value = response.data.data.domains || []
      cache.set(CACHE_KEYS.MAIL_DOMAINS, mailDomains.value, TTL.LONG)
    }
  } catch (e) {
    console.error('Failed to load mail domains', e)
  }
}

const fetchMailAccounts = async (forceRefresh = false) => {
  if (!forceRefresh) {
    const cached = cache.get(CACHE_KEYS.MAIL_ACCOUNTS)
    if (cached) {
      mailAccounts.value = cached
      return
    }
  }
  
  try {
    const response = await api.get('/mail/accounts')
    if (response.data.success) {
      mailAccounts.value = response.data.data.accounts || []
      cache.set(CACHE_KEYS.MAIL_ACCOUNTS, mailAccounts.value, TTL.LONG)
    }
  } catch (e) {
    console.error('Failed to load accounts', e)
  }
}

const fetchMailForwards = async () => {
  try {
    const response = await api.get('/mail/forwards')
    if (response.data.success) {
      mailForwards.value = response.data.data.forwards || []
    }
  } catch (e) {
    console.error('Failed to load forwards', e)
  }
}

const loadMailTab = async (forceRefresh = false) => {
  // Check cache for mail accounts
  if (!forceRefresh) {
    const cachedAccounts = cache.get(CACHE_KEYS.MAIL_ACCOUNTS)
    const cachedDomains = cache.get(CACHE_KEYS.MAIL_DOMAINS)
    if (cachedAccounts && cachedDomains) {
      mailAccounts.value = cachedAccounts
      mailDomains.value = cachedDomains
      mailLoading.value = false
      // Still fetch status, forwards and recent migrations in background
      fetchMailStatus()
      fetchMailForwards()
      fetchMigrations()
      fetchDavImports()
      return
    }
  }
  
  mailLoading.value = true
  await Promise.all([
    fetchMailStatus(),
    fetchMailDomains(forceRefresh),
    fetchMailAccounts(forceRefresh),
    fetchMailForwards(),
    fetchMigrations(),
    fetchDavImports()
  ])
  mailLoading.value = false
}

const createMailAccount = async () => {
  mailSubmitting.value = true
  try {
    const response = await api.post('/mail/accounts', {
      email: `${newMailAccount.value.email}@${newMailAccount.value.domain}`,
      password: newMailAccount.value.password
    })
    
    if (response.data.success) {
      toast.success('Account created')
      mailCreateModal.value = false
      newMailAccount.value = { email: '', domain: '', password: '' }
      await fetchMailAccounts()
    } else {
      toast.error(response.data.error || 'Failed to create account')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create account')
  } finally {
    mailSubmitting.value = false
  }
}

const deleteMailItem = async () => {
  if (!mailDeleteModal.value.item) return
  
  mailSubmitting.value = true
  try {
    let response
    if (mailDeleteModal.value.type === 'account') {
      response = await api.delete(`/mail/accounts/${encodeURIComponent(mailDeleteModal.value.item.email)}`)
    } else {
      response = await api.delete(`/mail/forwards/${encodeURIComponent(mailDeleteModal.value.item.source)}`)
    }
    
    if (response.data.success) {
      toast.success(`${mailDeleteModal.value.type === 'account' ? 'Account' : 'Forward'} deleted`)
      mailDeleteModal.value = { show: false, type: null, item: null }
      await Promise.all([fetchMailAccounts(), fetchMailForwards()])
    } else {
      toast.error(response.data.error || 'Delete failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Delete failed')
  } finally {
    mailSubmitting.value = false
  }
}

const resetMailPassword = async () => {
  if (!mailResetPasswordModal.value.account || !newMailPassword.value) return
  
  mailSubmitting.value = true
  try {
    const response = await api.post(`/mail/accounts/${encodeURIComponent(mailResetPasswordModal.value.account.email)}/password`, {
      password: newMailPassword.value
    })
    
    if (response.data.success) {
      toast.success('Password reset successfully')
      mailResetPasswordModal.value = { show: false, account: null }
      newMailPassword.value = ''
    } else {
      toast.error(response.data.error || 'Failed to reset password')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to reset password')
  } finally {
    mailSubmitting.value = false
  }
}

const toggleForcePasswordChange = async (account) => {
  if (!account?.email || forcePwChangePending.value) return

  const next = !account.force_password_change
  forcePwChangePending.value = account.email
  try {
    const response = await api.post(
      `/mail/accounts/${encodeURIComponent(account.email)}/force-password-change`,
      { enabled: next }
    )

    if (response.data.success) {
      // Optimistically update the row so the badge/colour flips instantly.
      account.force_password_change = next
      toast.success(next
        ? `${account.email} will be asked to set a new password on next login`
        : `Forced password change cleared for ${account.email}`)
    } else {
      toast.error(response.data.error || 'Failed to update flag')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update flag')
  } finally {
    forcePwChangePending.value = null
  }
}

// Suspend blocks login (Outlook/webmail/IMAP/SMTP) but keeps receiving mail.
// Resume re-enables it. Suspending opens a small confirm modal (optional
// reason); resuming is a direct toggle since it is non-destructive.
const openSuspendModal = (account) => {
  mailSuspendModal.value = { show: true, account, reason: '', busy: false }
}

const confirmSuspendAccount = async () => {
  const account = mailSuspendModal.value.account
  if (!account?.email) return

  mailSuspendModal.value.busy = true
  try {
    const response = await api.post(
      `/mail/accounts/${encodeURIComponent(account.email)}/suspend`,
      { reason: mailSuspendModal.value.reason || undefined }
    )
    if (response.data.success) {
      account.suspended = true
      toast.success(`${account.email} can no longer log in (mail is still being received)`)
      mailSuspendModal.value = { show: false, account: null, reason: '', busy: false }
    } else {
      toast.error(response.data.error || 'Failed to suspend account')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to suspend account')
  } finally {
    mailSuspendModal.value.busy = false
  }
}

const resumeMailAccount = async (account) => {
  if (!account?.email || suspendPending.value) return

  suspendPending.value = account.email
  try {
    const response = await api.post(
      `/mail/accounts/${encodeURIComponent(account.email)}/resume`
    )
    if (response.data.success) {
      account.suspended = false
      toast.success(`Login resumed for ${account.email}`)
    } else {
      toast.error(response.data.error || 'Failed to resume account')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to resume account')
  } finally {
    suspendPending.value = null
  }
}

const openMailRedirectModal = (email) => {
  const existingForwards = forwardsBySource.value[email] || []
  const hasKeepCopy = existingForwards.includes(email)
  
  mailRedirectModal.value = {
    show: true,
    source: email,
    destinations: existingForwards.filter(d => d !== email), // exclude self-forward from list
    newDestination: '',
    keepCopy: hasKeepCopy,
    loading: false
  }
}

const addMailForward = async () => {
  const dest = mailRedirectModal.value.newDestination.trim().toLowerCase()
  if (!dest) {
    toast.error('Enter a destination email')
    return
  }
  
  // Basic email validation
  if (!dest.includes('@') || !dest.includes('.')) {
    toast.error('Invalid email format')
    return
  }
  
  // Check if already exists
  if (mailRedirectModal.value.destinations.includes(dest) || dest === mailRedirectModal.value.source) {
    toast.error('This forward already exists')
    return
  }
  
  mailRedirectModal.value.loading = true
  try {
    const res = await api.post('/mail/forwards', {
      source: mailRedirectModal.value.source,
      destination: dest
    })
    
    if (!res.data.success) {
      throw new Error(res.data.error || 'Failed to add forward')
    }
    
    mailRedirectModal.value.destinations.push(dest)
    mailRedirectModal.value.newDestination = ''
    await fetchMailForwards()
    toast.success('Forward added')
  } catch (e) {
    toast.error(e.response?.data?.error || e.message || 'Failed to add forward')
  } finally {
    mailRedirectModal.value.loading = false
  }
}

const removeMailForward = async (destination) => {
  mailRedirectModal.value.loading = true
  try {
    // The API removes by source, but we need to remove specific destination
    // We need to check if the backend supports removing a specific forward
    const res = await api.delete(`/mail/forwards/${encodeURIComponent(mailRedirectModal.value.source)}`, {
      data: { destination }
    })
    
    if (!res.data.success) {
      throw new Error(res.data.error || 'Failed to remove forward')
    }
    
    mailRedirectModal.value.destinations = mailRedirectModal.value.destinations.filter(d => d !== destination)
    await fetchMailForwards()
    toast.success('Forward removed')
  } catch (e) {
    toast.error(e.response?.data?.error || e.message || 'Failed to remove forward')
  } finally {
    mailRedirectModal.value.loading = false
  }
}

const toggleMailKeepCopy = async () => {
  const source = mailRedirectModal.value.source
  const shouldKeep = !mailRedirectModal.value.keepCopy
  
  mailRedirectModal.value.loading = true
  try {
    if (shouldKeep) {
      // Add self-forward to keep copy
      const res = await api.post('/mail/forwards', {
        source: source,
        destination: source
      })
      if (!res.data.success) {
        throw new Error(res.data.error || 'Failed to enable keep copy')
      }
      toast.success('Local copy enabled')
    } else {
      // Remove self-forward
      const res = await api.delete(`/mail/forwards/${encodeURIComponent(source)}`, {
        data: { destination: source }
      })
      if (!res.data.success) {
        throw new Error(res.data.error || 'Failed to disable keep copy')
      }
      toast.success('Local copy disabled')
    }
    
    mailRedirectModal.value.keepCopy = shouldKeep
    await fetchMailForwards()
  } catch (e) {
    toast.error(e.response?.data?.error || e.message || 'Failed to update')
  } finally {
    mailRedirectModal.value.loading = false
  }
}

const closeMailRedirectModal = () => {
  mailRedirectModal.value = { 
    show: false, 
    source: '', 
    destinations: [], 
    newDestination: '', 
    keepCopy: false,
    loading: false 
  }
}

const openMailCreateModal = () => {
  newMailAccount.value.domain = mailDomainList.value[0] || ''
  showMailCreatePassword.value = false
  mailCreateModal.value = true
}

const generateMailPassword = () => {
  const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&*'
  let password = ''
  for (let i = 0; i < 16; i++) {
    password += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  if (mailCreateModal.value) {
    newMailAccount.value.password = password
  } else {
    newMailPassword.value = password
  }
}

// IMAP Migration Functions
const fetchMigrations = async () => {
  mailMigrationsLoading.value = true
  try {
    // Pull the recent batch (running + recently finished) so the progress
    // card can show absolute counts/sizes and the "verified" result, not
    // just a percentage. Migrations are run centrally and aren't tagged to
    // one domain, so we surface the whole recent list here.
    const response = await api.get('/imap-migration?limit=20')
    if (response.data.success) {
      const data = response.data.data || {}
      mailMigrations.value = Array.isArray(data.migrations) ? data.migrations : []
      serverHostname.value = data.server_hostname || serverHostname.value || ''
    }
  } catch (e) {
    console.error('Failed to load migrations', e)
  } finally {
    mailMigrationsLoading.value = false
  }
}

const openMigratePopup = () => {
  mailMigratePopup.value = true
  fetchMigrations()
}

// Dedicated Migration tab loader — email migrations + contacts/calendar imports.
const loadMigrationTab = (forceRefresh = false) => {
  fetchMigrations()
  fetchDavImports()
  // Needed for the export user dropdown (cached, so cheap to call).
  fetchMailAccounts(forceRefresh)
}

// ── Contacts / Calendar migration ────────────────────────────────────
const fetchDavImports = async () => {
  davImportsLoading.value = true
  try {
    const response = await api.get('/migration/dav-import?limit=50')
    if (response.data.success) {
      davImports.value = response.data.data?.migrations || []
    }
  } catch (e) {
    console.error('Failed to load contacts/calendar imports', e)
  } finally {
    davImportsLoading.value = false
  }
}

// Clears the history list only — imported contacts/events stay in FlowOne.
const clearDavImports = async () => {
  if (!confirm('Clear the entire import history list? Imported data is not affected.')) return
  davImportsClearing.value = true
  try {
    const response = await api.delete('/migration/dav-import')
    if (response.data.success) {
      davImports.value = []
      toast.success('Import history cleared')
    } else {
      toast.error(response.data.message || response.data.error || 'Could not clear history')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || e.response?.data?.error || 'Could not clear history')
  } finally {
    davImportsClearing.value = false
  }
}

const onDavFileChange = (event) => {
  const file = event.target.files?.[0]
  if (!file) return
  const name = (file.name || '').toLowerCase()
  if (name.endsWith('.csv')) davForm.value.format = 'csv'
  else if (name.endsWith('.ics') || name.endsWith('.ical')) davForm.value.format = 'ics'
  else if (name.endsWith('.vcf') || name.endsWith('.vcard')) davForm.value.format = 'vcf'
  else davForm.value.format = ''
  davForm.value.fileName = file.name
  const reader = new FileReader()
  reader.onload = () => { davForm.value.data = String(reader.result || '') }
  reader.onerror = () => toast.error('Could not read file')
  reader.readAsText(file)
}

const resetDavForm = () => {
  davForm.value = { userEmail: '', type: 'contacts', fileName: '', data: '', format: '' }
}

const submitDavImport = async () => {
  const email = davForm.value.userEmail.trim().toLowerCase()
  if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
    toast.error('Enter a valid destination user email')
    return
  }
  if (!davForm.value.data.trim()) {
    toast.error('Choose a file to import first')
    return
  }
  davSubmitting.value = true
  try {
    const response = await api.post('/migration/dav-import', {
      user_email: email,
      type: davForm.value.type,
      data: davForm.value.data,
      format: davForm.value.format || undefined,
      source_label: davForm.value.fileName || undefined,
    })
    if (response.data.success) {
      const d = response.data.data || {}
      toast.success(`Imported ${d.imported || 0} new, ${d.updated || 0} updated`)
      resetDavForm()
      fetchDavImports()
    } else {
      toast.error(response.data.message || response.data.error || 'Import failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || e.response?.data?.error || 'Import failed')
  } finally {
    davSubmitting.value = false
  }
}

// ── Contacts / Calendar export ───────────────────────────────────────
// Runs one export and triggers the file download. Returns true on success.
const runDavExport = async (email, type) => {
  try {
    const response = await api.post('/migration/dav-export', { user_email: email, type })
    if (response.data.success) {
      const d = response.data.data || {}
      const content = d.data || ''
      if (!content.trim()) {
        toast.error(`No ${type} found for ${email}`)
        return false
      }
      const blob = new Blob([content], { type: d.mime || 'text/plain' })
      const url = window.URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = d.filename || `${type}.txt`
      document.body.appendChild(a)
      a.click()
      a.remove()
      window.URL.revokeObjectURL(url)
      toast.success(`Exported ${d.count || 0} ${type === 'calendar' ? 'event(s)' : 'contact(s)'}`)
      return true
    }
    toast.error(response.data.message || response.data.error || 'Export failed')
    return false
  } catch (e) {
    toast.error(e.response?.data?.message || e.response?.data?.error || 'Export failed')
    return false
  }
}

// type: 'contacts' | 'calendar' | 'both'
const exportDav = async (type) => {
  const email = davExportEmail.value.trim().toLowerCase()
  if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
    toast.error('Select a user to export')
    return
  }
  davExporting.value = type
  try {
    if (type === 'both') {
      await runDavExport(email, 'contacts')
      await runDavExport(email, 'calendar')
    } else {
      await runDavExport(email, type)
    }
  } finally {
    davExporting.value = ''
  }
}

// ── Batch contacts/calendar import ───────────────────────────────────
const isValidEmail = (v) => /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(String(v || '').trim())

const davTypeFromName = (fileName) => {
  const n = (fileName || '').toLowerCase()
  if (n.endsWith('.ics') || n.endsWith('.ical')) return 'calendar'
  if (n.endsWith('.vcf') || n.endsWith('.vcard') || n.endsWith('.csv')) return 'contacts'
  return null
}

const davFormatFromName = (fileName) => {
  const n = (fileName || '').toLowerCase()
  if (n.endsWith('.csv')) return 'csv'
  if (n.endsWith('.ics') || n.endsWith('.ical')) return 'ics'
  if (n.endsWith('.vcf') || n.endsWith('.vcard')) return 'vcf'
  return ''
}

// Filename (minus extension) -> destination mailbox. A stem that is already a
// full email wins; otherwise build "<stem>@<domain>". Returns null if unresolvable.
const resolveBatchEmail = (fileName, domain) => {
  const stem = (fileName || '').replace(/\.[^.]+$/, '').trim().toLowerCase()
  if (!stem) return null
  if (stem.includes('@')) return isValidEmail(stem) ? stem : null
  const dom = String(domain || '').trim().replace(/^@/, '').toLowerCase()
  if (!dom) return null
  const email = `${stem}@${dom}`
  return isValidEmail(email) ? email : null
}

// Best-effort default domain from the server hostname (drop a mail subdomain).
const guessMailDomain = () => {
  const host = String(serverHostname.value || '').trim().toLowerCase()
  if (!host) return ''
  const parts = host.split('.')
  if (parts.length > 2 && ['mail', 'webmail', 'imap', 'smtp', 'mx'].includes(parts[0])) {
    return parts.slice(1).join('.')
  }
  return host
}

const setDavMode = (mode) => {
  davMode.value = mode
  if (mode === 'batch' && !davBatchDomain.value) davBatchDomain.value = guessMailDomain()
}

const classifyBatchRow = (row) => {
  if (!row.type) { row.status = 'error'; row.error = 'Unsupported file type'; return }
  if (!row.email) { row.status = 'error'; row.error = 'Cannot resolve email — set Domain'; return }
  row.status = 'ready'
  row.error = null
}

const onDavBatchFiles = (event) => {
  const files = Array.from(event.target.files || [])
  if (!files.length) return
  if (!davBatchDomain.value) davBatchDomain.value = guessMailDomain()
  for (const file of files) {
    const fileName = file.name || ''
    const row = {
      file,
      fileName,
      email: resolveBatchEmail(fileName, davBatchDomain.value),
      type: davTypeFromName(fileName),
      format: davFormatFromName(fileName),
      status: 'ready',
      error: null,
      counts: null,
    }
    classifyBatchRow(row)
    davBatchRows.value.push(row)
  }
  event.target.value = '' // allow re-selecting the same files
}

// Re-resolve emails when the domain changes (only for not-yet-imported rows).
watch(davBatchDomain, () => {
  for (const row of davBatchRows.value) {
    if (row.status === 'completed' || row.status === 'importing') continue
    row.email = resolveBatchEmail(row.fileName, davBatchDomain.value)
    classifyBatchRow(row)
  }
})

const removeDavBatchRow = (idx) => { davBatchRows.value.splice(idx, 1) }
const clearDavBatch = () => { davBatchRows.value = [] }

// Import every runnable row sequentially (idempotent upsert on the backend, and
// a 120s/call timeout, so one-at-a-time is the safe choice). Files are read
// lazily inside the loop so hundreds of files don't all sit in memory at once.
const runDavBatch = async () => {
  if (davBatchReadyCount.value === 0) {
    toast.error('No valid files to import')
    return
  }
  davBatchRunning.value = true
  let ok = 0
  let failed = 0
  try {
    for (const row of davBatchRows.value) {
      if (row.status !== 'ready' && row.status !== 'failed') continue
      row.status = 'importing'
      row.error = null
      try {
        const data = await row.file.text()
        if (!data.trim()) {
          row.status = 'failed'
          row.error = 'Empty file'
          failed++
          continue
        }
        const response = await api.post('/migration/dav-import', {
          user_email: row.email,
          type: row.type,
          data,
          format: row.format || undefined,
          source_label: row.fileName || undefined,
        })
        if (response.data.success) {
          const d = response.data.data || {}
          row.counts = { imported: d.imported || 0, updated: d.updated || 0, total: d.total || 0 }
          row.status = 'completed'
          ok++
        } else {
          row.status = 'failed'
          row.error = response.data.message || response.data.error || 'Import failed'
          failed++
        }
      } catch (e) {
        row.status = 'failed'
        row.error = e.response?.data?.message || e.response?.data?.error || 'Import failed'
        failed++
      }
    }
  } finally {
    davBatchRunning.value = false
  }
  if (ok) toast.success(`Imported ${ok} file(s)` + (failed ? `, ${failed} failed` : ''))
  else if (failed) toast.error(`All ${failed} import(s) failed`)
  fetchDavImports()
}

const selectMigrationType = (type) => {
  mailMigratePopup.value = false
  resetMigrationForm()
  resetPreflight()
  autoSourceHost.value = ''
  // The destination is always this server — show it pre-filled so the user
  // doesn't have to know/type it (still editable for the rare cross-server case).
  newMigration.value.destHost = serverHostname.value || ''
  if (type === 'single') {
    mailMigrateSingleModal.value = true
  } else {
    mailMigrateMultipleModal.value = true
  }
}

const resetMigrationForm = () => {
  newMigration.value = {
    sourceHost: '',
    sourceSsl: true,
    destHost: '',
    destSsl: true,
    email: '',
    destEmail: '',
    oldPassword: '',
    newPassword: '',
    accountsText: '',
    accountsFile: null,
    mode: 'initial',
    createMailbox: true,
  }
  mailMigrateMultipleTab.value = 'paste'
}

const startSingleMigration = async () => {
  if (!newMigration.value.sourceHost || !newMigration.value.email || !newMigration.value.oldPassword || !newMigration.value.newPassword) {
    toast.error('Please fill in all required fields')
    return
  }

  mailMigrateSubmitting.value = true
  try {
    // Destination address: defaults to the source address (server-to-server
    // move) unless an explicit one is given (e.g. Gmail -> domain mailbox).
    const destEmail = (newMigration.value.destEmail || newMigration.value.email).trim()

    // Optionally provision the destination mailbox first so imapsync has
    // somewhere to write. Bulk endpoint is idempotent (skips existing).
    if (newMigration.value.createMailbox) {
      try {
        await api.post('/mail/accounts/bulk', {
          accounts: [{ email: destEmail, password: newMigration.value.newPassword }],
          // Migrated mailbox gets a temp password — force the user to set their
          // own on first webmail login.
          force_password_change: true,
        })
      } catch (e) {
        toast.error(e.response?.data?.error || 'Could not pre-create mailbox; continuing')
      }
    }

    const response = await api.post('/imap-migration/start', {
      source_host: newMigration.value.sourceHost,
      source_ssl: newMigration.value.sourceSsl,
      dest_host: newMigration.value.destHost || serverHostname.value || undefined,
      dest_ssl: newMigration.value.destSsl,
      migration_mode: newMigration.value.mode,
      accounts: [{
        email: newMigration.value.email,
        source_password: newMigration.value.oldPassword,
        dest_email: destEmail,
        dest_password: newMigration.value.newPassword,
      }],
    })

    if (response.data.success) {
      toast.success('Migration started')
      mailMigrateSingleModal.value = false
      mailMigrateStatusSection.value = true
      fetchMigrations()
    } else {
      toast.error(response.data.error || 'Failed to start migration')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to start migration')
  } finally {
    mailMigrateSubmitting.value = false
  }
}

const parseAccountsText = (text) => {
  const lines = text.trim().split('\n').filter(l => l.trim() && !l.trim().startsWith('#'))
  const accounts = []

  for (const line of lines) {
    const parts = line.split(';').map(p => p.trim())
    // Two accepted formats, mapped to the migration engine contract:
    //   3 cols: email;old_password;new_password          (same address both sides)
    //   4 cols: source_email;old_password;dest_email;new_password (distinct dest,
    //           e.g. Gmail -> a domain mailbox)
    if (parts.length >= 4 && parts[0] && parts[2]) {
      accounts.push({
        email: parts[0],
        source_password: parts[1],
        dest_email: parts[2],
        dest_password: parts[3],
      })
    } else if (parts.length === 3 && parts[0]) {
      accounts.push({
        email: parts[0],
        source_password: parts[1],
        dest_email: parts[0],
        dest_password: parts[2],
      })
    }
  }

  return accounts
}

const handleFileUpload = (event) => {
  const file = event.target.files[0]
  if (!file) return
  
  const reader = new FileReader()
  reader.onload = (e) => {
    newMigration.value.accountsText = e.target.result
  }
  reader.readAsText(file)
}

const downloadMigrationExample = () => {
  const exampleContent = `# IMAP Migration Example File
# One account per line. Two accepted formats:
#   Same address on both servers:  email;old_password;new_password
#   Different destination address: source_email;old_password;dest_email;new_password
user1@example.com;oldpassword1;newpassword1
user2@example.com;oldpassword2;newpassword2
old@gmail.com;oldpassword3;user3@newdomain.com;newpassword3
`
  const blob = new Blob([exampleContent], { type: 'text/plain' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'migration_accounts_example.txt'
  a.click()
  URL.revokeObjectURL(url)
}

const startMultipleMigration = async () => {
  if (!newMigration.value.sourceHost) {
    toast.error('Old server is required')
    return
  }
  
  const accounts = parseAccountsText(newMigration.value.accountsText)
  if (accounts.length === 0) {
    toast.error('No valid accounts found. Format: email;old_password;new_password (or source_email;old_password;dest_email;new_password) per line')
    return
  }

  mailMigrateSubmitting.value = true
  try {
    // Optionally provision all destination mailboxes first (idempotent).
    if (newMigration.value.createMailbox) {
      try {
        await api.post('/mail/accounts/bulk', {
          accounts: accounts.map((a) => ({ email: a.email, password: a.dest_password })),
          // Migrated mailboxes get temp passwords — force a change on first login.
          force_password_change: true,
        })
      } catch (e) {
        toast.error(e.response?.data?.error || 'Could not pre-create some mailboxes; continuing')
      }
    }

    const response = await api.post('/imap-migration/start', {
      source_host: newMigration.value.sourceHost,
      source_ssl: newMigration.value.sourceSsl,
      dest_host: newMigration.value.destHost || serverHostname.value || undefined,
      dest_ssl: newMigration.value.destSsl,
      migration_mode: newMigration.value.mode,
      accounts,
    })

    if (response.data.success) {
      toast.success(`Migration started for ${accounts.length} accounts`)
      mailMigrateMultipleModal.value = false
      mailMigrateStatusSection.value = true
      fetchMigrations()
    } else {
      toast.error(response.data.error || 'Failed to start migration')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to start migration')
  } finally {
    mailMigrateSubmitting.value = false
  }
}

const resetPreflight = () => {
  migratePreflight.value = { running: false, ran: false, allOk: false, results: [] }
}

// Validate source + destination credentials/connectivity without moving mail.
const runMigrationPreflight = async (accounts) => {
  if (!newMigration.value.sourceHost) {
    toast.error('Old server is required')
    return
  }
  if (!accounts || accounts.length === 0) {
    toast.error('Add at least one account first')
    return
  }

  migratePreflight.value = { running: true, ran: false, allOk: false, results: [] }
  try {
    const response = await api.post('/imap-migration/preflight', {
      source_host: newMigration.value.sourceHost,
      source_ssl: newMigration.value.sourceSsl,
      dest_host: newMigration.value.destHost || serverHostname.value || undefined,
      dest_ssl: newMigration.value.destSsl,
      accounts,
    })

    if (response.data.success) {
      const d = response.data.data || {}
      migratePreflight.value = { running: false, ran: true, allOk: !!d.all_ok, results: d.results || [] }
      if (d.all_ok) {
        toast.success('All connections succeeded')
      } else {
        toast.warning('Some connections failed — see details below')
      }
    } else {
      migratePreflight.value = { running: false, ran: true, allOk: false, results: [] }
      toast.error(response.data.error || 'Preflight failed')
    }
  } catch (e) {
    migratePreflight.value = { running: false, ran: true, allOk: false, results: [] }
    toast.error(e.response?.data?.error || 'Preflight failed')
  }
}

const runPreflightSingle = () => {
  if (!newMigration.value.email || !newMigration.value.oldPassword) {
    toast.error('Enter the source email and current password first')
    return
  }
  const destEmail = (newMigration.value.destEmail || newMigration.value.email).trim()
  runMigrationPreflight([{
    email: newMigration.value.email,
    source_password: newMigration.value.oldPassword,
    dest_email: destEmail,
    dest_password: newMigration.value.newPassword || newMigration.value.oldPassword,
  }])
}

const runPreflightBatch = () => {
  runMigrationPreflight(parseAccountsText(newMigration.value.accountsText))
}

const cancelMigration = async (id) => {
  try {
    const response = await api.post(`/imap-migration/${id}/cancel`)
    if (response.data.success) {
      toast.success('Migration cancelled')
      fetchMigrations()
    } else {
      toast.error(response.data.error || 'Failed to cancel migration')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to cancel migration')
  }
}

// Remove a single terminal migration row from the list (and its log file).
const deleteMigration = async (id) => {
  if (migrationDeleting.value.has(id)) return
  migrationDeleting.value = new Set(migrationDeleting.value).add(id)
  try {
    const response = await api.delete(`/imap-migration/${id}`)
    if (response.data.success) {
      mailMigrations.value = mailMigrations.value.filter((m) => m.id !== id)
    } else {
      toast.error(response.data.error || 'Failed to delete migration')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete migration')
  } finally {
    const next = new Set(migrationDeleting.value)
    next.delete(id)
    migrationDeleting.value = next
  }
}

// Remove every completed / failed / cancelled migration from the list.
const clearFinishedMigrations = async () => {
  const finished = mailMigrations.value.filter((m) => TERMINAL_MIGRATION_STATUSES.includes(m.status))
  if (finished.length === 0) return

  migrationClearing.value = true
  let removed = 0
  try {
    for (const m of finished) {
      try {
        const response = await api.delete(`/imap-migration/${m.id}`)
        if (response.data.success) {
          mailMigrations.value = mailMigrations.value.filter((x) => x.id !== m.id)
          removed++
        }
      } catch (e) {
        // Keep going; report the count at the end.
      }
    }
    if (removed > 0) {
      toast.success(`Cleared ${removed} migration${removed === 1 ? '' : 's'}`)
    } else {
      toast.error('Could not clear migrations')
    }
  } finally {
    migrationClearing.value = false
  }
}

// ── Delta-sync scheduler ─────────────────────────────────────────────
// Initial copy -> periodic delta top-ups -> final cutover sync -> one
// post-cutover sweep. All non-destructive; safe to re-run.
const migrationBusy = ref(new Set())
const intervalOptions = [
  { value: 60, label: 'Every hour' },
  { value: 180, label: 'Every 3 hours' },
  { value: 360, label: 'Every 6 hours' },
  { value: 720, label: 'Every 12 hours' },
  { value: 1440, label: 'Daily' },
]

const formatSchedTime = (s) => {
  if (!s) return '—'
  const d = new Date(String(s).replace(' ', 'T'))
  return isNaN(d.getTime()) ? s : d.toLocaleString()
}

const setMigrationSchedule = async (m, enabled) => {
  try {
    const interval = Number(m.delta_interval_minutes) || 360
    const response = await api.post(`/imap-migration/${m.id}/schedule`, { enabled, interval_minutes: interval })
    if (response.data.success) {
      toast.success(enabled ? 'Auto delta sync enabled' : 'Auto delta sync disabled')
      fetchMigrations()
    } else {
      toast.error(response.data.error || 'Failed to update schedule')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update schedule')
  }
}

const updateMigrationInterval = async (m, minutes) => {
  m.delta_interval_minutes = Number(minutes)
  // Persist immediately only when auto-sync is already on.
  if (Number(m.schedule_enabled) === 1) {
    await setMigrationSchedule(m, true)
  }
}

const runMigrationDelta = async (m) => {
  if (migrationBusy.value.has(m.id)) return
  migrationBusy.value.add(m.id)
  try {
    const response = await api.post(`/imap-migration/${m.id}/run`, { mode: 'delta' })
    if (response.data.success) {
      toast.success('Delta sync started')
      fetchMigrations()
    } else {
      toast.error(response.data.error || 'Failed to start delta sync')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to start delta sync')
  } finally {
    migrationBusy.value.delete(m.id)
  }
}

const finalizeMigration = async (m) => {
  if (!window.confirm('Run the FINAL cutover sync now and schedule a catch-up sweep in 48h?\n\nDo this right after switching MX/DNS to the new server.')) {
    return
  }
  try {
    const response = await api.post(`/imap-migration/${m.id}/finalize`, { sweep_after_hours: 48, run_final_now: true })
    if (response.data.success) {
      toast.success('Final cutover sync started — a catch-up sweep is scheduled in 48h')
      fetchMigrations()
    } else {
      toast.error(response.data.error || 'Failed to start final sync')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to start final sync')
  }
}

// Migration Progress Detail Modal
const migrationDetailModal = ref(false)
const migrationDetail = ref(null)
const migrationLogs = ref('')
const migrationLogsOffset = ref(0)
const migrationLogsLoading = ref(false)
let migrationLogsInterval = null

const openMigrationDetail = async (migration) => {
  migrationDetail.value = migration
  migrationLogs.value = ''
  migrationLogsOffset.value = 0
  migrationDetailModal.value = true
  await fetchMigrationLogs(migration.id, true)
  
  // Start polling for updates if running
  if (migration.status === 'running') {
    migrationLogsInterval = setInterval(() => {
      fetchMigrationLogs(migration.id, false)
    }, 2000)
  }
}

const closeMigrationDetail = () => {
  migrationDetailModal.value = false
  migrationDetail.value = null
  if (migrationLogsInterval) {
    clearInterval(migrationLogsInterval)
    migrationLogsInterval = null
  }
}

const fetchMigrationLogs = async (id, initial = false) => {
  if (initial) migrationLogsLoading.value = true
  try {
    const response = await api.get(`/imap-migration/${id}/logs`, {
      params: {
        since: initial ? 0 : migrationLogsOffset.value,
        tail: 200
      }
    })
    if (response.data.success) {
      const data = response.data.data
      if (initial) {
        migrationLogs.value = data.logs
      } else if (data.logs) {
        migrationLogs.value += data.logs
      }
      migrationLogsOffset.value = data.offset
      
      // Update migration detail with latest status
      if (migrationDetail.value) {
        migrationDetail.value.status = data.status
        migrationDetail.value.progress = data.progress
        migrationDetail.value.current_account = data.current_account
        migrationDetail.value.accounts = data.accounts
        // Keep the absolute counters live so the modal shows real numbers.
        migrationDetail.value.total_accounts = data.total_accounts
        migrationDetail.value.completed_accounts = data.completed_accounts
        migrationDetail.value.total_messages = data.total_messages
        migrationDetail.value.transferred_messages = data.transferred_messages
        migrationDetail.value.transferred_bytes = data.transferred_bytes
        migrationDetail.value.verified = data.verified
        
        // Stop polling if completed
        if (data.status !== 'running' && migrationLogsInterval) {
          clearInterval(migrationLogsInterval)
          migrationLogsInterval = null
          fetchMigrations() // Refresh main list
        }
      }
    }
  } catch (e) {
    console.error('Failed to fetch logs', e)
  } finally {
    migrationLogsLoading.value = false
  }
}

const formatBytes = (bytes) => {
  if (!bytes || bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

// Absolute message counts for the migration progress card.
const formatNumber = (n) => {
  const v = Number(n ?? 0)
  return Number.isFinite(v) ? v.toLocaleString() : '0'
}

// ============================================
// DNS Tab State & Logic
// ============================================
const dnsLoading = ref(true)
const dnsStatus = ref(null)
const dnsZones = ref([])
const dnsSelectedZone = ref(null)
const dnsRecords = ref([])
const dnsCreateRecordModal = ref(false)
const dnsEditModal = ref({ show: false, record: null })
const dnsDeleteModal = ref({ show: false, record: null })
const dnsSubmitting = ref(false)
const dnsFixingIssues = ref(false)
const dnsIssuesResult = ref(null)

const editingDnsRecord = ref({
  name: '',
  type: 'A',
  content: '',
  ttl: 3600,
  prio: null
})

const newDnsRecord = ref({
  name: '',
  type: 'A',
  content: '',
  ttl: 3600,
  prio: null
})

const dnsRecordTypes = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA']

const fetchDnsStatus = async () => {
  try {
    const response = await api.get('/dns/status')
    if (response.data.success) {
      dnsStatus.value = response.data.data
    }
  } catch (e) {
    console.error(e)
  }
}

const fetchDnsZones = async (forceRefresh = false) => {
  if (!forceRefresh) {
    const cached = cache.get(CACHE_KEYS.DNS_ZONES)
    if (cached) {
      dnsZones.value = cached
      return
    }
  }
  
  try {
    const response = await api.get('/dns/zones')
    if (response.data.success) {
      dnsZones.value = response.data.data.zones || []
      cache.set(CACHE_KEYS.DNS_ZONES, dnsZones.value, TTL.LONG)
    }
  } catch (e) {
    toast.error('Failed to load DNS zones')
  }
}

const loadDnsTab = async (forceRefresh = false) => {
  if (!forceRefresh) {
    const cached = cache.get(CACHE_KEYS.DNS_ZONES)
    if (cached) {
      dnsZones.value = cached
      dnsLoading.value = false
      fetchDnsStatus() // Still fetch status in background
      return
    }
  }
  
  dnsLoading.value = true
  await Promise.all([fetchDnsStatus(), fetchDnsZones(forceRefresh)])
  dnsLoading.value = false
}

const selectDnsZone = async (zone) => {
  dnsSelectedZone.value = zone
  
  try {
    const response = await api.get(`/dns/zones/${zone.name}/records`)
    if (response.data.success) {
      dnsRecords.value = response.data.data.records || []
    }
  } catch (e) {
    toast.error('Failed to load DNS records')
  }
}

const addDnsRecord = async () => {
  dnsSubmitting.value = true
  try {
    const response = await api.post('/dns/records', {
      zone: dnsSelectedZone.value.name,
      ...newDnsRecord.value
    })
    
    if (response.data.success) {
      toast.success('Record added')
      dnsCreateRecordModal.value = false
      newDnsRecord.value = { name: '', type: 'A', content: '', ttl: 3600, prio: null }
      await selectDnsZone(dnsSelectedZone.value)
    } else {
      toast.error(response.data.error || 'Failed to add record')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to add record')
  } finally {
    dnsSubmitting.value = false
  }
}

// Check DNS issues (show only, don't fix)
const checkDnsIssues = async () => {
  if (!dnsSelectedZone.value) return
  
  dnsFixingIssues.value = true
  dnsIssuesResult.value = null
  
  try {
    const response = await api.post(`/dns/zones/${dnsSelectedZone.value.name}/fix-issues`, { mode: 'check' })
    if (response.data.success) {
      dnsIssuesResult.value = response.data.data
      if (response.data.data.issues_found === 0) {
        toast.success('No DNS issues found!')
      }
    } else {
      toast.error(response.data.error || 'Failed to check DNS issues')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to check DNS issues')
  } finally {
    dnsFixingIssues.value = false
  }
}

// Fix DNS issues (actually apply fixes)
const fixDnsIssues = async () => {
  if (!dnsSelectedZone.value) return
  
  dnsFixingIssues.value = true
  
  try {
    const response = await api.post(`/dns/zones/${dnsSelectedZone.value.name}/fix-issues`, { mode: 'fix' })
    if (response.data.success) {
      dnsIssuesResult.value = response.data.data
      if (response.data.data.fixed?.length > 0) {
        toast.success(`Fixed ${response.data.data.fixed.length} DNS issue(s)`)
        await selectDnsZone(dnsSelectedZone.value)
      }
    } else {
      toast.error(response.data.error || 'Failed to fix DNS issues')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to fix DNS issues')
  } finally {
    dnsFixingIssues.value = false
  }
}

const deleteDnsRecord = async () => {
  if (!dnsDeleteModal.value.record) return
  
  dnsSubmitting.value = true
  try {
    const response = await api.delete(`/dns/records/${dnsDeleteModal.value.record.id}`)
    if (response.data.success) {
      toast.success('Record deleted')
      dnsDeleteModal.value = { show: false, record: null }
      await selectDnsZone(dnsSelectedZone.value)
    } else {
      toast.error(response.data.error || 'Failed to delete record')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete record')
  } finally {
    dnsSubmitting.value = false
  }
}

const openEditDnsRecord = (record) => {
  editingDnsRecord.value = {
    name: record.name,
    type: record.type,
    content: record.content,
    ttl: record.ttl,
    prio: record.prio ?? record.priority ?? null
  }
  dnsEditModal.value = { show: true, record }
}

const updateDnsRecord = async () => {
  if (!dnsEditModal.value.record) return
  
  dnsSubmitting.value = true
  try {
    const response = await api.put(`/dns/records/${dnsEditModal.value.record.id}`, {
      zone: dnsSelectedZone.value.name,
      ...editingDnsRecord.value
    })
    
    if (response.data.success) {
      toast.success('Record updated')
      dnsEditModal.value = { show: false, record: null }
      await selectDnsZone(dnsSelectedZone.value)
    } else {
      toast.error(response.data.error || 'Failed to update record')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update record')
  } finally {
    dnsSubmitting.value = false
  }
}

// ============================================
// WordPress Tab State & Logic
// ============================================
const wpTemplates = ref([])
const wpInstalledApps = ref([])
const wpSites = ref([])
const wpLoading = ref(true)
const wpInstalling = ref(false)
const wpSummaries = ref({})
const wpSummaryLoading = ref({})
const wpUpdating = ref({}) // Track which domain is being updated
const wpShowInstallModal = ref(false)
const wpShowUninstallModal = ref(false)
const wpShowUpdateModal = ref(false)
const wpUpdateDomain = ref(null)
const wpUpdatePlugins = ref([])
const wpUpdatePluginsLoading = ref(false)
const wpUpdateSelection = ref({
  core: true,
  allPlugins: true,
  selectedPlugins: [],
  themes: true
})
const wpSelectedTemplate = ref(null)
const wpSelectedApp = ref(null)
const wpFilterSite = ref('')
const wpSearchQuery = ref('')

const wpInstallForm = ref({
  domain: '',
  app_slug: '',
  admin_email: '',
  admin_user: 'admin',
  admin_password: '',
  site_title: '',
  db_name: '',
})

// Site databases for WordPress install
const wpSiteDatabases = ref([])
const wpSiteDbLoading = ref(false)
const wpUseExistingDb = ref(false)

const wpFilteredApps = computed(() => {
  let apps = wpInstalledApps.value
  if (wpFilterSite.value) {
    apps = apps.filter(app => app.domain === wpFilterSite.value)
  }
  if (wpSearchQuery.value) {
    const query = wpSearchQuery.value.toLowerCase()
    apps = apps.filter(app => 
      app.domain.toLowerCase().includes(query) ||
      app.app_name?.toLowerCase().includes(query)
    )
  }
  return apps
})

const wpUniqueSites = computed(() => {
  const domains = [...new Set(wpInstalledApps.value.map(app => app.domain))]
  return domains.sort()
})

const fetchWpData = async (forceRefresh = false) => {
  // Check cache first
  if (!forceRefresh) {
    const cached = cache.get(CACHE_KEYS.WORDPRESS)
    if (cached) {
      wpTemplates.value = cached.templates || []
      wpInstalledApps.value = cached.apps || []
      wpSites.value = cached.sites || []
      wpSummaries.value = cached.summaries || {}
      wpLoading.value = false
      return
    }
  }
  
  wpLoading.value = true
  try {
    const [templatesRes, appsRes, sitesRes] = await Promise.all([
      api.get('/apps/templates'),
      api.get('/apps'),
      api.get('/sites'),
    ])
    wpTemplates.value = templatesRes.data?.data?.templates || []
    wpInstalledApps.value = appsRes.data?.data?.applications || []
    wpSites.value = sitesRes.data?.data?.vhosts || []
    
    // Fetch WP summaries for WordPress apps
    const wpApps = wpInstalledApps.value.filter(app => app.app_slug === 'wordpress')
    for (const app of wpApps.slice(0, 10)) {
      await fetchWpSummary(app.domain)
    }
    
    // Cache the data
    cache.set(CACHE_KEYS.WORDPRESS, {
      templates: wpTemplates.value,
      apps: wpInstalledApps.value,
      sites: wpSites.value,
      summaries: wpSummaries.value
    }, TTL.LONG)
  } catch (e) {
    toast.error('Failed to load WordPress data')
  } finally {
    wpLoading.value = false
  }
}

const fetchWpSummary = async (domain) => {
  if (wpSummaries.value[domain] || wpSummaryLoading.value[domain]) return
  wpSummaryLoading.value[domain] = true
  try {
    const response = await api.get(`/wordpress/${domain}`)
    if (response.data.success && response.data.data?.installed !== false) {
      wpSummaries.value[domain] = response.data.data
    } else {
      wpSummaries.value[domain] = null
    }
  } catch (e) {
    wpSummaries.value[domain] = null
  } finally {
    wpSummaryLoading.value[domain] = false
  }
}

// Open update modal for a WordPress site
const openWpUpdateModal = async (domain) => {
  wpUpdateDomain.value = domain
  wpUpdatePlugins.value = []
  wpUpdateSelection.value = {
    core: true,
    allPlugins: true,
    selectedPlugins: [],
    themes: true
  }
  wpShowUpdateModal.value = true
  
  // Load plugins for this domain
  wpUpdatePluginsLoading.value = true
  try {
    const response = await api.get(`/wordpress/${domain}/plugins`)
    console.log(`[WP Update] Plugins response for ${domain}:`, response.data)
    if (response.data.success) {
      const allPlugins = response.data.data.plugins || []
      // Filter to only plugins with updates
      wpUpdatePlugins.value = allPlugins.filter(
        p => p.update_available || p.update === 'available'
      )
      console.log(`[WP Update] Plugins with updates:`, wpUpdatePlugins.value.map(p => ({ name: p.name, version: p.version, update_version: p.update_version })))
      // Pre-select all plugins
      wpUpdateSelection.value.selectedPlugins = wpUpdatePlugins.value.map(p => p.name)
    }
  } catch (e) {
    console.error('[WP Update] Failed to load plugins', e)
  } finally {
    wpUpdatePluginsLoading.value = false
  }
}

// Close update modal
const closeWpUpdateModal = () => {
  wpShowUpdateModal.value = false
  wpUpdateDomain.value = null
  wpUpdatePlugins.value = []
}

// Toggle plugin selection
const togglePluginSelection = (pluginName) => {
  const idx = wpUpdateSelection.value.selectedPlugins.indexOf(pluginName)
  if (idx > -1) {
    wpUpdateSelection.value.selectedPlugins.splice(idx, 1)
  } else {
    wpUpdateSelection.value.selectedPlugins.push(pluginName)
  }
  // Update allPlugins checkbox state
  wpUpdateSelection.value.allPlugins = wpUpdateSelection.value.selectedPlugins.length === wpUpdatePlugins.value.length
}

// Toggle all plugins
const toggleAllPlugins = () => {
  if (wpUpdateSelection.value.allPlugins) {
    wpUpdateSelection.value.selectedPlugins = wpUpdatePlugins.value.map(p => p.name)
  } else {
    wpUpdateSelection.value.selectedPlugins = []
  }
}

// Perform selective update
const performWpUpdate = async () => {
  const domain = wpUpdateDomain.value
  if (!domain || wpUpdating.value[domain]) return
  
  const { core, selectedPlugins, themes } = wpUpdateSelection.value
  const hasCoreUpdate = wpSummaries.value[domain]?.core_updates?.length > 0
  
  // Check if anything meaningful is selected
  const willUpdateCore = core && hasCoreUpdate
  const willUpdatePlugins = selectedPlugins.length > 0
  const willUpdateThemes = themes // themes update is always attempted if selected
  
  if (!willUpdateCore && !willUpdatePlugins && !willUpdateThemes) {
    toast.warning('Please select at least one item to update')
    return
  }
  
  wpUpdating.value[domain] = true
  closeWpUpdateModal()
  
  try {
    let updatedItems = []
    let errors = []
    
    // Update core if selected and update available
    if (willUpdateCore) {
      try {
        console.log(`[WP Update] Updating core for ${domain}`)
        const response = await api.post(`/wordpress/${domain}/core/update`)
        console.log(`[WP Update] Core response:`, response.data)
        if (response.data.success) {
          updatedItems.push('Core')
        } else {
          errors.push('Core: ' + (response.data.error || 'Failed'))
        }
      } catch (e) {
        console.error(`[WP Update] Core error:`, e)
        errors.push('Core: ' + (e.response?.data?.error || 'Failed'))
      }
    }
    
    // Update selected plugins one by one
    if (willUpdatePlugins) {
      for (const plugin of selectedPlugins) {
        try {
          console.log(`[WP Update] Updating plugin ${plugin} for ${domain}`)
          const response = await api.post(`/wordpress/${domain}/plugins/update`, { plugin })
          console.log(`[WP Update] Plugin ${plugin} response:`, response.data)
          if (response.data.success) {
            updatedItems.push(plugin)
          } else {
            errors.push(`${plugin}: ${response.data.error || 'Failed'}`)
          }
        } catch (e) {
          console.error(`[WP Update] Plugin ${plugin} error:`, e)
          errors.push(`${plugin}: ${e.response?.data?.error || 'Failed'}`)
        }
      }
    }
    
    // Update themes if selected
    if (willUpdateThemes) {
      try {
        console.log(`[WP Update] Updating themes for ${domain}`)
        const response = await api.post(`/wordpress/${domain}/themes/update-all`)
        console.log(`[WP Update] Themes response:`, response.data)
        if (response.data.success) {
          updatedItems.push('Themes')
        } else {
          errors.push('Themes: ' + (response.data.error || 'Failed'))
        }
      } catch (e) {
        console.error(`[WP Update] Themes error:`, e)
        errors.push('Themes: ' + (e.response?.data?.error || 'Failed'))
      }
    }
    
    // Show results with details
    console.log(`[WP Update] Results - Updated: ${updatedItems.length}, Errors: ${errors.length}`, { updatedItems, errors })
    
    if (errors.length === 0 && updatedItems.length > 0) {
      toast.success(`${domain}: Updated ${updatedItems.join(', ')}`)
    } else if (updatedItems.length > 0) {
      toast.warning(`${domain}: Updated ${updatedItems.length}, failed ${errors.length}`)
      console.warn('[WP Update] Errors:', errors)
    } else if (errors.length > 0) {
      toast.error(`${domain}: Update failed - ${errors[0]}`)
    } else {
      toast.info(`${domain}: Nothing to update`)
    }
    
    // Refresh summary to show updated state
    wpSummaryLoading.value[domain] = false
    wpSummaries.value[domain] = null
    await fetchWpSummary(domain)
    
  } catch (e) {
    console.error(`[WP Update] Unexpected error:`, e)
    toast.error(e.response?.data?.error || `${domain}: Failed to update`)
  } finally {
    wpUpdating.value[domain] = false
  }
}

// Check if a WordPress site has updates available
const wpHasUpdates = (domain) => {
  const summary = wpSummaries.value[domain]
  if (!summary) return false
  const pluginUpdates = summary.plugins?.updates_available || 0
  const coreUpdates = summary.core_updates?.length || 0
  return pluginUpdates > 0 || coreUpdates > 0
}

// Get total updates count for a WordPress site
const wpTotalUpdates = (domain) => {
  const summary = wpSummaries.value[domain]
  if (!summary) return 0
  const pluginUpdates = summary.plugins?.updates_available || 0
  const coreUpdates = summary.core_updates?.length ? 1 : 0
  return pluginUpdates + coreUpdates
}

const generateWpPassword = () => {
  const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*'
  let password = ''
  for (let i = 0; i < 16; i++) {
    password += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  return password
}

const generateWpDbName = (domain) => {
  if (!domain) return ''
  return domain.replace(/[^a-z0-9]/gi, '_').substring(0, 16) + '_wp'
}

const openWpInstallModal = (template) => {
  wpSelectedTemplate.value = template
  wpInstallForm.value = {
    domain: '',
    app_slug: template.slug,
    admin_email: '',
    admin_user: 'admin',
    admin_password: generateWpPassword(),
    site_title: '',
    db_name: '',
  }
  wpShowInstallModal.value = true
}

const onWpDomainChange = async () => {
  const domain = wpInstallForm.value.domain
  
  // Reset database state
  wpSiteDatabases.value = []
  wpUseExistingDb.value = false
  
  if (!domain) {
    wpInstallForm.value.db_name = ''
    return
  }
  
  // Fetch databases for this site
  wpSiteDbLoading.value = true
  try {
    const response = await api.get(`/sites/${domain}/databases`)
    if (response.data.success && response.data.data?.databases?.length > 0) {
      wpSiteDatabases.value = response.data.data.databases
      // Auto-select first database if available
      wpUseExistingDb.value = true
      wpInstallForm.value.db_name = wpSiteDatabases.value[0].name
    } else {
      // No databases found, generate new name
      wpInstallForm.value.db_name = generateWpDbName(domain)
    }
  } catch (e) {
    // Failed to fetch databases, generate new name
    console.error('Failed to fetch site databases', e)
    wpInstallForm.value.db_name = generateWpDbName(domain)
  } finally {
    wpSiteDbLoading.value = false
  }
  
  wpInstallForm.value._lastDomain = domain
}

const installWpApp = async () => {
  if (!wpInstallForm.value.domain || !wpInstallForm.value.admin_email) {
    toast.error('Site and admin email are required')
    return
  }
  
  // Validate database name
  if (!wpInstallForm.value.db_name) {
    wpInstallForm.value.db_name = generateWpDbName(wpInstallForm.value.domain)
  }
  
  wpInstalling.value = true
  try {
    const payload = {
      domain: wpInstallForm.value.domain,
      app_slug: wpInstallForm.value.app_slug,
      admin_email: wpInstallForm.value.admin_email,
      admin_user: wpInstallForm.value.admin_user || 'admin',
      admin_password: wpInstallForm.value.admin_password,
      site_title: wpInstallForm.value.site_title || wpInstallForm.value.domain,
      db_name: wpInstallForm.value.db_name,
    }
    
    console.log('Installing WordPress with payload:', payload)
    
    const response = await api.post('/apps/install', payload)
    
    if (response.data.success) {
      toast.success('WordPress installed successfully!')
      wpShowInstallModal.value = false
      await fetchWpData()
    } else {
      toast.error(response.data.error || 'Installation failed')
      console.error('Install failed:', response.data)
    }
  } catch (e) {
    const errorMsg = e.response?.data?.error || e.message || 'Installation failed'
    toast.error(errorMsg)
    console.error('Install error:', e.response?.data || e)
  } finally {
    wpInstalling.value = false
  }
}

const openWpUninstallModal = (app) => {
  wpSelectedApp.value = app
  wpShowUninstallModal.value = true
}

const confirmWpUninstall = async () => {
  if (!wpSelectedApp.value) return
  try {
    await api.delete(`/apps/${wpSelectedApp.value.id}`, {
      data: { keep_files: false, keep_database: false }
    })
    toast.success('Application uninstalled')
    wpShowUninstallModal.value = false
    wpSelectedApp.value = null
    await fetchWpData()
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to uninstall')
  }
}

const getWpAppIcon = (template) => {
  const iconMap = { wordpress: 'edit_note', laravel: 'code', joomla: 'article', drupal: 'hub', prestashop: 'shopping_cart' }
  return iconMap[template.slug] || template.icon || 'apps'
}

const formatWpDate = (date) => {
  if (!date) return '-'
  return new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

// ============================================
// Docker Tab State & Logic
// ============================================
const dockerStatus = ref(null)
const dockerContainers = ref([])
const dockerLoading = ref(true)
const dockerInstalling = ref(false)

const fetchDockerStatus = async (forceRefresh = false) => {
  // Check cache first
  if (!forceRefresh) {
    const cachedStatus = cache.get(CACHE_KEYS.DOCKER_STATUS)
    const cachedContainers = cache.get(CACHE_KEYS.DOCKER_CONTAINERS)
    if (cachedStatus) {
      dockerStatus.value = cachedStatus
      if (cachedContainers) dockerContainers.value = cachedContainers
      dockerLoading.value = false
      return
    }
  }
  
  dockerLoading.value = true
  try {
    const response = await api.get('/docker/status')
    if (response.data.success) {
      dockerStatus.value = response.data.data
      cache.set(CACHE_KEYS.DOCKER_STATUS, dockerStatus.value, TTL.LONG)
      if (dockerStatus.value.running) {
        await fetchDockerContainers(forceRefresh)
      }
    }
  } catch (e) {
    dockerStatus.value = { installed: false, running: false }
  } finally {
    dockerLoading.value = false
  }
}

const fetchDockerContainers = async (forceRefresh = false) => {
  if (!forceRefresh) {
    const cached = cache.get(CACHE_KEYS.DOCKER_CONTAINERS)
    if (cached) {
      dockerContainers.value = cached
      return
    }
  }
  
  try {
    const response = await api.get('/docker/containers')
    if (response.data.success) {
      dockerContainers.value = response.data.data.containers || []
      cache.set(CACHE_KEYS.DOCKER_CONTAINERS, dockerContainers.value, TTL.LONG)
    }
  } catch (e) {
    console.error('Failed to fetch containers', e)
  }
}

const installDocker = async () => {
  dockerInstalling.value = true
  try {
    const response = await api.post('/docker/install', { include_compose: true })
    if (response.data.success) {
      toast.success('Docker installed successfully')
      await fetchDockerStatus()
    } else {
      toast.error(response.data.error || 'Failed to install Docker')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to install Docker')
  } finally {
    dockerInstalling.value = false
  }
}

const restartContainer = async (id) => {
  try {
    const response = await api.post(`/docker/containers/${id}/restart`)
    if (response.data.success) {
      toast.success('Container restarted')
      await fetchDockerContainers()
    } else {
      toast.error(response.data.error || 'Failed to restart container')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restart container')
  }
}

const stopContainer = async (id) => {
  try {
    const response = await api.post(`/docker/containers/${id}/stop`)
    if (response.data.success) {
      toast.success('Container stopped')
      await fetchDockerContainers()
    } else {
      toast.error(response.data.error || 'Failed to stop container')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to stop container')
  }
}

const startContainer = async (id) => {
  try {
    const response = await api.post(`/docker/containers/${id}/start`)
    if (response.data.success) {
      toast.success('Container started')
      await fetchDockerContainers()
    } else {
      toast.error(response.data.error || 'Failed to start container')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to start container')
  }
}

// ============================================
// Load data based on active tab
// ============================================
const loadTabData = (tab, forceRefresh = false) => {
  switch (tab) {
    case 'services':
      fetchServices(forceRefresh)
      break
    case 'addons':
      fetchVpnConnections()
      fetchNasConnections()
      break
    case 'databases':
      fetchDatabases(forceRefresh)
      break
    case 'ssl':
      fetchCertificates(forceRefresh)
      if (!sslHealth.value || forceRefresh) fetchSslHealth()
      break
    case 'mail':
      loadMailTab(forceRefresh)
      break
    case 'migration':
      loadMigrationTab(forceRefresh)
      break
    case 'dns':
      loadDnsTab(forceRefresh)
      break
    case 'wordpress':
      fetchWpData(forceRefresh)
      break
    case 'docker':
      fetchDockerStatus(forceRefresh)
      break
  }
}

// Force refresh current tab
const refreshCurrentTab = () => {
  loadTabData(activeTab.value, true)
}

watch(activeTab, (newTab) => {
  loadTabData(newTab)
}, { immediate: true })

onMounted(() => {
  loadTabData(activeTab.value)
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="page-header">
      <div>
        <h1 class="page-title">Overview</h1>
        <p class="text-surface-500 text-sm mt-1 hidden sm:block">Services, addons, databases, SSL, mail, DNS, WordPress, and Docker</p>
      </div>
    </div>

    <!-- Tabs -->
    <div 
      ref="tabsContainer"
      class="tabs-container"
      :class="{ 'can-scroll-left': canScrollLeft, 'can-scroll-right': canScrollRight }"
    >
      <nav ref="tabsNav" class="tab-nav" @scroll="updateScrollIndicators">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id; scrollToActiveTab()"
          :class="['tab-btn', { 'active': activeTab === tab.id }]"
          :title="tab.label"
        >
          <span class="material-symbols-rounded tab-icon">{{ tab.icon }}</span>
          <span class="tab-label">{{ tab.label }}</span>
        </button>
      </nav>
    </div>

    <!-- Services Tab -->
    <div v-if="activeTab === 'services'" class="space-y-4 sm:space-y-6">
      <div class="flex justify-end">
        <button @click="loadTabData('services', true)" class="btn-secondary" :disabled="servicesLoading">
          <span class="material-symbols-rounded" :class="servicesLoading && 'animate-spin'">refresh</span>
          <span class="hidden sm:inline">Refresh</span>
        </button>
      </div>

      <!-- Agent Health Alert -->
      <div v-if="isAgentCrashed" class="card border-l-4 border-red-500 bg-red-50 dark:bg-red-900/20">
        <div class="p-4">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-red-100 dark:bg-red-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-red-600 dark:text-red-400 text-xl">error</span>
              </div>
              <div>
                <h3 class="font-semibold text-red-800 dark:text-red-300">VPS Admin Agent Crashed</h3>
                <p class="text-sm text-red-600 dark:text-red-400">The agent service is not running. Check the logs below for details.</p>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <button 
                @click="fetchAgentLogs" 
                class="btn-secondary btn-sm"
                :disabled="agentLogsLoading"
              >
                <span v-if="agentLogsLoading" class="spinner"></span>
                <span v-else class="material-symbols-rounded">refresh</span>
                Refresh Logs
              </button>
              <button 
                @click="agentLogsExpanded = !agentLogsExpanded" 
                class="btn-ghost btn-sm"
              >
                <span class="material-symbols-rounded">{{ agentLogsExpanded ? 'expand_less' : 'expand_more' }}</span>
              </button>
            </div>
          </div>
          
          <div v-if="agentLogsExpanded" class="space-y-3">
            <!-- Error Summary -->
            <div v-if="agentLogs?.errors" class="bg-red-100 dark:bg-red-900/30 rounded-lg p-3">
              <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-rounded text-red-600 text-lg">warning</span>
                <span class="font-medium text-red-800 dark:text-red-300 text-sm">Recent Errors</span>
              </div>
              <pre class="text-xs text-red-700 dark:text-red-300 whitespace-pre-wrap font-mono max-h-32 overflow-auto">{{ agentLogs.errors }}</pre>
            </div>
            
            <!-- Full Logs -->
            <div class="bg-surface-900 dark:bg-surface-950 rounded-lg p-3">
              <div class="flex items-center justify-between mb-2">
                <span class="text-surface-400 text-sm">Full Logs (last {{ agentLogs?.lines || 50 }} lines)</span>
                <button 
                  @click="copyToClipboard(agentLogs?.logs || '', 'Logs copied to clipboard')"
                  class="text-surface-400 hover:text-white text-xs flex items-center gap-1"
                >
                  <span class="material-symbols-rounded text-sm">content_copy</span>
                  Copy
                </button>
              </div>
              <pre v-if="agentLogs?.logs" class="text-xs text-green-400 whitespace-pre-wrap font-mono max-h-64 overflow-auto">{{ agentLogs.logs }}</pre>
              <div v-else-if="agentLogsLoading" class="flex items-center justify-center py-4">
                <span class="spinner"></span>
              </div>
              <p v-else class="text-surface-500 text-sm">No logs available yet. Click "Refresh Logs" to fetch.</p>
            </div>
            
            <!-- Manual Commands -->
            <div class="bg-surface-100 dark:bg-surface-800 rounded-lg p-3">
              <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-rounded text-surface-500 text-lg">terminal</span>
                <span class="font-medium text-sm">Manual Commands (SSH)</span>
              </div>
              <div class="space-y-2">
                <div class="flex items-center gap-2">
                  <code class="text-xs bg-surface-200 dark:bg-surface-700 px-2 py-1 rounded font-mono flex-1">journalctl -u vpsadmin-agent -n 100 --no-pager</code>
                  <button 
                    @click="copyToClipboard('journalctl -u vpsadmin-agent -n 100 --no-pager')"
                    class="btn-ghost btn-sm"
                  >
                    <span class="material-symbols-rounded text-sm">content_copy</span>
                  </button>
                </div>
                <div class="flex items-center gap-2">
                  <code class="text-xs bg-surface-200 dark:bg-surface-700 px-2 py-1 rounded font-mono flex-1">systemctl restart vpsadmin-agent && systemctl status vpsadmin-agent</code>
                  <button 
                    @click="copyToClipboard('systemctl restart vpsadmin-agent && systemctl status vpsadmin-agent')"
                    class="btn-ghost btn-sm"
                  >
                    <span class="material-symbols-rounded text-sm">content_copy</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div v-if="servicesLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <div v-else-if="services.length" class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="table min-w-[600px]">
            <thead>
              <tr class="bg-surface-50 dark:bg-surface-800/50">
                <th>Service</th>
                <th>Status</th>
                <th class="hidden sm:table-cell">Enabled</th>
                <th class="hidden md:table-cell">Uptime</th>
                <th class="hidden lg:table-cell">Memory</th>
                <th class="hidden lg:table-cell">PID</th>
                <th class="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="service in services" :key="service.name">
                <td>
                  <div class="flex items-center gap-2 sm:gap-3">
                    <div :class="[
                      'w-8 h-8 sm:w-10 sm:h-10 rounded-xl flex items-center justify-center shrink-0',
                      service.active 
                        ? 'bg-green-100 dark:bg-green-500/20' 
                        : 'bg-red-100 dark:bg-red-500/20'
                    ]">
                      <span :class="[
                        'material-symbols-rounded text-base sm:text-xl',
                        service.active ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'
                      ]">dns</span>
                    </div>
                    <span class="font-semibold text-sm sm:text-base truncate max-w-[120px] sm:max-w-none">{{ service.name }}</span>
                  </div>
                </td>
                <td>
                  <StatusBadge :status="service.status || (service.active ? 'running' : 'stopped')" />
                </td>
                <td class="hidden sm:table-cell">
                  <span :class="service.enabled ? 'text-green-500' : 'text-surface-400'">
                    {{ service.enabled ? 'Yes' : 'No' }}
                  </span>
                </td>
                <td class="text-surface-500 hidden md:table-cell">{{ service.uptime || '—' }}</td>
                <td class="text-surface-500 hidden lg:table-cell">{{ service.memory || '—' }}</td>
                <td class="font-mono text-surface-500 text-sm hidden lg:table-cell">{{ service.pid || '—' }}</td>
                <td>
                <div class="flex justify-end gap-1">
                  <button
                    v-if="service.active"
                    @click="performServiceAction(service, 'reload')"
                    class="btn-ghost btn-sm"
                    :disabled="serviceActionLoading[service.name]"
                    title="Reload"
                  >
                    <span v-if="serviceActionLoading[service.name] === 'reload'" class="spinner"></span>
                    <span v-else class="material-symbols-rounded">sync</span>
                  </button>
                  
                  <button
                    v-if="service.active"
                    @click="showServiceConfirm(service, 'restart')"
                    class="btn-ghost btn-sm"
                    :disabled="serviceActionLoading[service.name]"
                    title="Restart"
                  >
                    <span v-if="serviceActionLoading[service.name] === 'restart'" class="spinner"></span>
                    <span v-else class="material-symbols-rounded">restart_alt</span>
                  </button>

                  <button
                    v-if="service.active"
                    @click="showServiceConfirm(service, 'stop')"
                    class="btn-ghost btn-sm text-red-500"
                    :disabled="serviceActionLoading[service.name]"
                    title="Stop"
                  >
                    <span v-if="serviceActionLoading[service.name] === 'stop'" class="spinner"></span>
                    <span v-else class="material-symbols-rounded">stop_circle</span>
                  </button>

                  <button
                    v-if="!service.active"
                    @click="performServiceAction(service, 'start')"
                    class="btn-primary btn-sm"
                    :disabled="serviceActionLoading[service.name]"
                    title="Start"
                  >
                    <span v-if="serviceActionLoading[service.name] === 'start'" class="spinner"></span>
                    <span v-else class="material-symbols-rounded">play_circle</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>

      <div v-else class="card p-12 text-center">
        <span class="material-symbols-rounded text-5xl text-surface-300 mb-4 block">dns</span>
        <h3 class="text-lg font-medium mb-2">No Services</h3>
        <p class="text-surface-500">No services are configured for management.</p>
      </div>
    </div>

    <!-- Addons Tab -->
    <div v-if="activeTab === 'addons'" class="space-y-4 sm:space-y-6">
      <!-- VPN & NAS Status Cards -->
      <div v-if="vpnConnections.length > 0 || nasConnections.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- VPN Status Card -->
        <div v-if="vpnConnections.length > 0" class="card p-4">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">vpn_lock</span>
              </div>
              <div>
                <h3 class="font-semibold">VPN Tunnels</h3>
                <p class="text-xs text-surface-500">{{ vpnStats.connected }}/{{ vpnStats.total }} connected</p>
              </div>
            </div>
            <router-link to="/nas-storage?tab=vpn" class="btn-ghost btn-sm">
              <span class="material-symbols-rounded">open_in_new</span>
            </router-link>
          </div>
          <div class="space-y-2">
            <div 
              v-for="vpn in vpnConnections" 
              :key="vpn.name"
              class="flex items-center justify-between p-2 bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))] rounded-lg"
            >
              <div class="flex items-center gap-2">
                <span 
                  class="w-2 h-2 rounded-full"
                  :class="vpn.status === 'connected' ? 'bg-green-500' : vpn.status === 'error' ? 'bg-red-500' : 'bg-amber-500'"
                ></span>
                <span class="font-medium text-sm">{{ vpn.name }}</span>
              </div>
              <div class="flex items-center gap-2 text-xs">
                <span v-if="vpn.local_ip" class="text-surface-500 font-mono">{{ vpn.local_ip }}</span>
                <span 
                  :class="[
                    'px-2 py-0.5 rounded-full text-xs font-medium',
                    vpn.status === 'connected' ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400' :
                    vpn.status === 'error' ? 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400' :
                    'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400'
                  ]"
                >
                  {{ vpn.status }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- NAS Status Card -->
        <div v-if="nasConnections.length > 0" class="card p-4">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-purple-100 dark:bg-purple-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-purple-600 dark:text-purple-400">hard_drive</span>
              </div>
              <div>
                <h3 class="font-semibold">NAS Storage</h3>
                <p class="text-xs text-surface-500">{{ nasStats.active }}/{{ nasStats.total }} active</p>
              </div>
            </div>
            <router-link to="/nas-storage" class="btn-ghost btn-sm">
              <span class="material-symbols-rounded">open_in_new</span>
            </router-link>
          </div>
          <div class="space-y-2">
            <div 
              v-for="nas in nasConnections" 
              :key="nas.id"
              class="flex items-center justify-between p-2 bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))] rounded-lg"
            >
              <div class="flex items-center gap-2">
                <span 
                  class="w-2 h-2 rounded-full"
                  :class="nas.status === 'active' ? 'bg-green-500' : nas.status === 'error' ? 'bg-red-500' : 'bg-amber-500'"
                ></span>
                <span class="font-medium text-sm">{{ nas.name }}</span>
                <span v-if="nas.is_default" class="px-1.5 py-0.5 bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-400 text-xs rounded">default</span>
              </div>
              <div class="flex items-center gap-2 text-xs">
                <span class="text-surface-500 font-mono truncate max-w-[120px]" :title="nas.mount_point">{{ nas.mount_point }}</span>
                <span 
                  :class="[
                    'px-2 py-0.5 rounded-full text-xs font-medium',
                    nas.status === 'active' ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400' :
                    nas.status === 'error' ? 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400' :
                    'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400'
                  ]"
                >
                  {{ nas.status }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Email App Addons + Users & Groups -->
      <EmailAddonsPanel />
    </div>

    <!-- Databases Tab -->
    <div v-if="activeTab === 'databases'" class="space-y-4 sm:space-y-6">
      <div class="flex flex-col sm:flex-row justify-between gap-3 sm:items-center">
        <span v-if="getCacheAge(CACHE_KEYS.DATABASES) !== 'not cached'" class="text-xs text-surface-400 hidden sm:block">
          <span class="material-symbols-rounded text-sm align-middle">schedule</span>
          Updated {{ getCacheAge(CACHE_KEYS.DATABASES) }}
        </span>
        <span v-else class="hidden sm:block"></span>
        <div class="flex gap-2">
          <button @click="fetchDatabases(true)" class="btn-secondary" :disabled="dbLoading">
            <span class="material-symbols-rounded" :class="dbLoading && 'animate-spin'">refresh</span>
            <span class="hidden sm:inline">Refresh</span>
          </button>
          <button @click="dbCreateModal = true" class="btn-primary">
            <span class="material-symbols-rounded">add</span>
            <span class="hidden sm:inline">New Database</span>
          </button>
        </div>
      </div>

      <div class="card p-3 sm:p-4">
        <div class="flex flex-col sm:flex-row gap-3 sm:gap-4 sm:items-center">
          <div class="relative flex-1 min-w-0">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
            <input
              v-model="dbSearchQuery"
              type="text"
              class="input pl-10"
              placeholder="Search databases, users..."
            />
          </div>
          
          <div class="flex justify-between sm:justify-start items-center gap-3 sm:gap-4">
            <div class="flex items-center gap-2">
              <Toggle v-model="dbShowSystem" />
              <span class="text-sm cursor-pointer" @click="dbShowSystem = !dbShowSystem">System DBs</span>
            </div>
            
            <div class="flex gap-3 text-xs sm:text-sm text-surface-500">
              <span>{{ filteredDatabases.length }} dbs</span>
              <span>{{ formatSize(dbTotalSize) }}</span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="dbLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <div v-else class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="table min-w-[600px]">
            <thead>
              <tr class="bg-surface-50 dark:bg-surface-800/50">
                <th>Name</th>
                <th>Size</th>
                <th class="hidden sm:table-cell">Tables</th>
                <th class="hidden md:table-cell">Users</th>
                <th class="hidden lg:table-cell">Linked To</th>
                <th class="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="db in filteredDatabases" :key="db.name" :class="db.is_system ? 'opacity-60' : ''">
                <td>
                  <div class="flex items-center gap-2 sm:gap-3">
                    <div :class="[
                      'w-8 h-8 sm:w-10 sm:h-10 rounded-xl flex items-center justify-center shrink-0',
                      db.is_system 
                        ? 'bg-surface-100 dark:bg-surface-800' 
                        : 'bg-purple-100 dark:bg-purple-500/20'
                    ]">
                      <span :class="[
                        'material-symbols-rounded text-base sm:text-xl',
                        db.is_system 
                          ? 'text-surface-400' 
                          : 'text-purple-600 dark:text-purple-400'
                      ]">{{ db.is_system ? 'settings' : 'database' }}</span>
                    </div>
                  <div>
                    <span class="font-medium">{{ db.name }}</span>
                    <span v-if="db.is_system" class="ml-2 text-xs px-2 py-0.5 rounded-full bg-surface-200 dark:bg-surface-700 text-surface-500">
                      system
                    </span>
                  </div>
                </div>
              </td>
              <td>
                <span class="text-surface-500">{{ db.size_human || formatSize(db.size) }}</span>
              </td>
              <td>
                <span class="text-surface-500">{{ db.tables_count || 0 }}</span>
              </td>
              <td>
                <div class="flex flex-wrap gap-1">
                  <span 
                    v-for="user in (db.users || []).slice(0, 3)" 
                    :key="`${user.User}@${user.Host}`"
                    class="text-xs px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400"
                  >
                    {{ user.User }}
                  </span>
                  <span 
                    v-if="(db.users?.length || 0) > 3"
                    class="text-xs px-2 py-0.5 rounded-full bg-surface-100 dark:bg-surface-800 text-surface-500"
                  >
                    +{{ db.users.length - 3 }}
                  </span>
                  <span v-if="!db.users?.length" class="text-surface-400">-</span>
                </div>
              </td>
              <td>
                <span v-if="db.linked_site" class="text-xs px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400">
                  {{ db.linked_site }}
                </span>
                <span v-else class="text-surface-400">-</span>
              </td>
              <td class="text-right">
                <div class="flex items-center justify-end gap-1">
                  <button 
                    @click="openPhpMyAdmin(db.name)"
                    class="btn-ghost btn-sm text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-500/10"
                    :disabled="pmaLoading[db.name]"
                    title="Open in phpMyAdmin"
                  >
                    <span v-if="pmaLoading[db.name]" class="spinner-sm"></span>
                    <span v-else class="material-symbols-rounded">database</span>
                  </button>
                  <button 
                    v-if="!db.is_system"
                    @click="dbDeleteModal = { show: true, db }"
                    class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                    title="Delete Database"
                  >
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!filteredDatabases.length">
              <td colspan="6" class="py-12 text-center text-surface-400">
                <span class="material-symbols-rounded text-4xl mb-2 block">database</span>
                No databases found
              </td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- SSL Tab -->
    <div v-if="activeTab === 'ssl'" class="space-y-6">
      <div class="flex justify-between items-center">
        <span v-if="getCacheAge(CACHE_KEYS.SSL) !== 'not cached'" class="text-xs text-surface-400">
          <span class="material-symbols-rounded text-sm align-middle">schedule</span>
          Updated {{ getCacheAge(CACHE_KEYS.SSL) }}
        </span>
        <span v-else></span>
        <div class="flex gap-2">
          <button @click="fetchCertificates(true)" class="btn-secondary" :disabled="sslLoading">
            <span class="material-symbols-rounded" :class="sslLoading && 'animate-spin'">refresh</span>
            Refresh
          </button>
          <button @click="renewAllCerts" class="btn-secondary" :disabled="sslSubmitting">
            <span class="material-symbols-rounded">autorenew</span>
            Renew All
          </button>
          <button @click="openSslIssueModal()" class="btn-primary">
            <span class="material-symbols-rounded">add</span>
            Issue Certificate
          </button>
        </div>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="stat-card">
          <p class="text-surface-500 text-sm">Total</p>
          <p class="stat-value">{{ sslStats.total }}</p>
        </div>
        <div class="stat-card">
          <p class="text-surface-500 text-sm">Valid</p>
          <p class="stat-value text-green-600">{{ sslStats.valid }}</p>
        </div>
        <div class="stat-card">
          <p class="text-surface-500 text-sm">Expiring Soon</p>
          <p class="stat-value text-amber-600">{{ sslStats.expiring }}</p>
        </div>
        <div class="stat-card">
          <p class="text-surface-500 text-sm">Expired</p>
          <p class="stat-value text-red-600">{{ sslStats.expired }}</p>
        </div>
        <div class="stat-card">
          <p class="text-surface-500 text-sm">Self-Signed</p>
          <p class="stat-value text-surface-500">{{ sslStats.selfSigned }}</p>
        </div>
      </div>

      <!-- SSL Health Check -->
      <div v-if="sslHealth && sslHealth.issues?.length > 0" class="card p-4 border-l-4 border-amber-500">
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-amber-500 text-2xl">health_and_safety</span>
            <div class="flex items-center gap-2">
              <h3 class="font-semibold">SSL Health Issues</h3>
              <div class="relative group">
                <span class="material-symbols-rounded text-surface-400 hover:text-surface-600 cursor-help text-lg">info</span>
                <div class="absolute left-0 top-6 z-50 hidden group-hover:block w-80 p-4 bg-surface-800 text-white text-sm rounded-lg shadow-xl">
                  <p class="font-semibold mb-2">Issues Detected:</p>
                  <ul class="space-y-1 text-surface-300 mb-3">
                    <li><span class="text-red-400">Broken Symlinks</span> - Certificate files missing</li>
                    <li><span class="text-amber-400">Duplicates (-0001)</span> - Certbot created copies</li>
                    <li><span class="text-red-400">Dovecot Orphans</span> - Config references deleted certs</li>
                    <li><span class="text-amber-400">Expiring Soon</span> - Certs expiring in 14 days</li>
                    <li><span class="text-red-400">Expired</span> - Already expired certificates</li>
                    <li><span class="text-amber-400">Broken Renewal</span> - Invalid renewal configs</li>
                    <li><span class="text-amber-400">Insecure Permissions</span> - privkey.pem too open</li>
                    <li><span class="text-amber-400">Wrong Ownership</span> - Not owned by root</li>
                  </ul>
                  <p class="font-semibold mb-1">Fix Actions:</p>
                  <p class="text-surface-300">All fixes create automatic backups before making changes. If a fix fails, changes are rolled back.</p>
                </div>
              </div>
            </div>
            <p class="text-sm text-surface-500 ml-1">
              {{ sslHealth.summary.errors }} error(s), {{ sslHealth.summary.warnings }} warning(s)
            </p>
          </div>
          <button 
            v-if="sslHealth.summary.fixable > 0"
            @click="fixAllSslIssues" 
            class="btn-primary"
            :disabled="sslHealthLoading"
          >
            <span v-if="sslHealthLoading" class="spinner-sm mr-2"></span>
            <span class="material-symbols-rounded" v-else>auto_fix_high</span>
            Fix All ({{ sslHealth.summary.fixable }})
          </button>
        </div>
        
        <div class="space-y-2">
          <div 
            v-for="issue in sslHealth.issues" 
            :key="issue.id"
            class="flex items-center justify-between p-3 rounded-lg bg-surface-50 dark:bg-surface-800"
          >
            <div class="flex items-center gap-3">
              <span 
                :class="['material-symbols-rounded', issue.severity === 'error' ? 'text-red-500' : 'text-amber-500']"
              >
                {{ getSeverityIcon(issue.severity) }}
              </span>
              <div>
                <p class="font-medium">{{ issue.message }}</p>
                <p class="text-sm text-surface-500">{{ issue.details }}</p>
              </div>
            </div>
            <button 
              v-if="issue.fixable"
              @click="fixSslIssue(issue)" 
              class="btn-secondary btn-sm"
              :disabled="sslHealthFixing[issue.id]"
            >
              <span v-if="sslHealthFixing[issue.id]" class="spinner-sm"></span>
              <span class="material-symbols-rounded" v-else>build</span>
              Fix
            </button>
          </div>
        </div>
      </div>

      <!-- No Issues Banner -->
      <div v-else-if="sslHealth && sslHealth.issues?.length === 0" class="card p-4 border-l-4 border-green-500">
        <div class="flex items-center gap-3">
          <span class="material-symbols-rounded text-green-500 text-2xl">check_circle</span>
          <div class="flex items-center gap-2">
            <h3 class="font-semibold text-green-700 dark:text-green-400">All SSL Healthy</h3>
            <div class="relative group">
              <span class="material-symbols-rounded text-green-400 hover:text-green-600 cursor-help text-lg">info</span>
              <div class="absolute left-0 top-6 z-50 hidden group-hover:block w-72 p-3 bg-surface-800 text-white text-sm rounded-lg shadow-xl">
                <p class="mb-2">This health check monitors:</p>
                <ul class="text-surface-300 space-y-1">
                  <li>Certificate symlink integrity</li>
                  <li>Duplicate certificates (-0001)</li>
                  <li>Dovecot SSL config validity</li>
                  <li>Expiring/expired certificates</li>
                  <li>Certbot renewal configs</li>
                  <li>File permissions (privkey.pem)</li>
                  <li>Ownership (should be root)</li>
                </ul>
              </div>
            </div>
          </div>
          <p class="text-sm text-surface-500 ml-2">No issues detected</p>
        </div>
      </div>

      <div class="card p-4">
        <div class="flex flex-wrap gap-4 items-center">
          <div class="relative flex-1 min-w-[200px]">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
            <input
              v-model="sslSearchQuery"
              type="text"
              class="input pl-10"
              placeholder="Search by domain or issuer..."
            />
          </div>
          
          <select v-model="sslFilterType" class="input w-auto">
            <option value="all">All Certificates</option>
            <option value="sites">Sites Only</option>
            <option value="mail">With Mail Coverage</option>
            <option value="selfsigned">Self-Signed</option>
          </select>
          
          <select v-model="sslSortBy" class="input w-auto">
            <option value="expiry">Expiring Soon</option>
            <option value="days">Days Remaining</option>
            <option value="name">Name A-Z</option>
          </select>
        </div>
      </div>

      <div v-if="sslLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <div v-else class="card overflow-hidden">
        <table class="table">
          <thead>
            <tr class="bg-surface-50 dark:bg-surface-800/50">
              <th>Domain</th>
              <th>Issuer</th>
              <th>Valid Until</th>
              <th>Days Left</th>
              <th>Status</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="cert in filteredCertificates" :key="cert.domain">
              <td>
                <div class="flex items-center gap-3">
                  <div :class="[
                    'w-10 h-10 rounded-xl flex items-center justify-center',
                    getCertStatus(cert) === 'valid' 
                      ? 'bg-green-100 dark:bg-green-500/20'
                      : getCertStatus(cert) === 'expired'
                        ? 'bg-red-100 dark:bg-red-500/20'
                        : 'bg-amber-100 dark:bg-amber-500/20'
                  ]">
                    <span :class="['material-symbols-rounded', getStatusClass(cert)]">verified_user</span>
                  </div>
                  <div class="group relative">
                    <p class="font-medium">{{ cert.domain }}</p>
                    <p v-if="cert.sans?.length > 1" class="text-xs text-surface-500 cursor-help">
                      +{{ cert.sans.length - 1 }} more domain{{ cert.sans.length > 2 ? 's' : '' }}
                    </p>
                    <!-- Tooltip showing all domains -->
                    <div v-if="cert.sans?.length > 1" class="absolute left-0 top-full mt-1 z-50 hidden group-hover:block">
                      <div class="bg-surface-800 dark:bg-surface-900 text-white text-xs rounded-lg p-3 shadow-lg whitespace-nowrap">
                        <p class="font-medium mb-2 text-surface-400">Domains covered:</p>
                        <div class="space-y-1">
                          <p v-for="san in cert.sans" :key="san" class="flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-green-400 text-sm">verified</span>
                            {{ san }}
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </td>
              <td>
                <span class="text-surface-600 dark:text-surface-400">{{ cert.issuer || '—' }}</span>
              </td>
              <td>
                <span class="text-sm">{{ cert.valid_to }}</span>
              </td>
              <td>
                <span :class="[
                  'font-medium',
                  cert.days_remaining < 30 ? 'text-amber-500' : 'text-surface-600 dark:text-surface-400'
                ]">
                  {{ cert.days_remaining }}
                </span>
              </td>
              <td>
                <StatusBadge :status="getCertStatus(cert)" />
              </td>
              <td class="text-right">
                <div class="flex justify-end gap-1">
                  <button 
                    @click="renewCert(cert.domain)" 
                    class="btn-ghost btn-sm"
                    title="Renew"
                    :disabled="sslRenewing[cert.domain]"
                  >
                    <span class="material-symbols-rounded" :class="sslRenewing[cert.domain] && 'animate-spin'">autorenew</span>
                  </button>
                  <button 
                    @click="sslDeleteModal = { show: true, cert }" 
                    class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                    title="Delete"
                  >
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!filteredCertificates.length">
              <td colspan="6" class="py-12 text-center text-surface-400">
                <span class="material-symbols-rounded text-4xl mb-2 block">verified_user</span>
                No certificates found
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Mail Tab -->
    <div v-if="activeTab === 'mail'" class="space-y-6">
      <div class="flex justify-end gap-2">
        <button @click="loadMailTab(true)" class="btn-secondary" :disabled="mailLoading">
          <span class="material-symbols-rounded" :class="mailLoading && 'animate-spin'">refresh</span>
          Refresh
        </button>
        <button @click="mailShowSettings = !mailShowSettings" class="btn-secondary">
          <span class="material-symbols-rounded">settings</span>
          Settings
        </button>
        <button @click="openMailCreateModal" class="btn-primary">
          <span class="material-symbols-rounded">add</span>
          New Account
        </button>
      </div>

      <!-- Mail Server Settings -->
      <div v-if="mailShowSettings" class="card p-6">
        <h3 class="font-semibold mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded text-primary-500">dns</span>
          Mail Server Settings
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <h4 class="font-medium text-sm text-surface-500 mb-3">INCOMING MAIL</h4>
            <div class="space-y-3">
              <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
                <div class="flex justify-between items-center mb-2">
                  <span class="font-medium">IMAP</span>
                  <span class="text-xs px-2 py-1 rounded-full bg-green-100 dark:bg-green-500/20 text-green-600">Recommended</span>
                </div>
                <div class="text-sm space-y-1 text-surface-600 dark:text-surface-400">
                  <p><span class="text-surface-400">Server:</span> <span class="font-mono">{{ mailServerHost }}</span></p>
                  <p><span class="text-surface-400">Port:</span> <code class="bg-surface-200 dark:bg-surface-700 px-1 rounded">993</code></p>
                  <p><span class="text-surface-400">Security:</span> SSL/TLS</p>
                </div>
              </div>
              <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
                <div class="font-medium mb-2">POP3</div>
                <div class="text-sm space-y-1 text-surface-600 dark:text-surface-400">
                  <p><span class="text-surface-400">Server:</span> <span class="font-mono">{{ mailServerHost }}</span></p>
                  <p><span class="text-surface-400">Port:</span> <code class="bg-surface-200 dark:bg-surface-700 px-1 rounded">995</code></p>
                  <p><span class="text-surface-400">Security:</span> SSL/TLS</p>
                </div>
              </div>
            </div>
          </div>
          <div>
            <h4 class="font-medium text-sm text-surface-500 mb-3">OUTGOING MAIL (SMTP)</h4>
            <div class="space-y-3">
              <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
                <div class="flex justify-between items-center mb-2">
                  <span class="font-medium">SMTP (SSL)</span>
                  <span class="text-xs px-2 py-1 rounded-full bg-green-100 dark:bg-green-500/20 text-green-600">Recommended</span>
                </div>
                <div class="text-sm space-y-1 text-surface-600 dark:text-surface-400">
                  <p><span class="text-surface-400">Server:</span> <span class="font-mono">{{ mailServerHost }}</span></p>
                  <p><span class="text-surface-400">Port:</span> <code class="bg-surface-200 dark:bg-surface-700 px-1 rounded">465</code></p>
                  <p><span class="text-surface-400">Security:</span> SSL/TLS</p>
                </div>
              </div>
              <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
                <div class="font-medium mb-2">SMTP (STARTTLS)</div>
                <div class="text-sm space-y-1 text-surface-600 dark:text-surface-400">
                  <p><span class="text-surface-400">Server:</span> <span class="font-mono">{{ mailServerHost }}</span></p>
                  <p><span class="text-surface-400">Port:</span> <code class="bg-surface-200 dark:bg-surface-700 px-1 rounded">587</code></p>
                  <p><span class="text-surface-400">Security:</span> STARTTLS</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="stat-card">
          <div class="flex items-center gap-3">
            <span :class="['status-dot', mailStatus?.postfix?.running ? 'running' : 'stopped']"></span>
            <span class="font-medium">Postfix (SMTP)</span>
          </div>
          <div class="mt-2">
            <StatusBadge :status="mailStatus?.postfix?.running ? 'running' : 'stopped'" />
          </div>
        </div>
        
        <div class="stat-card">
          <div class="flex items-center gap-3">
            <span :class="['status-dot', mailStatus?.dovecot?.running ? 'running' : 'stopped']"></span>
            <span class="font-medium">Dovecot (IMAP)</span>
          </div>
          <div class="mt-2">
            <StatusBadge :status="mailStatus?.dovecot?.running ? 'running' : 'stopped'" />
          </div>
        </div>
        
        <div class="stat-card">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-surface-400">group</span>
            <span class="font-medium">Accounts</span>
          </div>
          <div class="stat-value mt-2">{{ mailAccounts.length }}</div>
        </div>
        
        <div class="stat-card">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-surface-400">forward_to_inbox</span>
            <span class="font-medium">Forwards</span>
          </div>
          <div class="stat-value mt-2">{{ mailForwards.length }}</div>
        </div>
        
        <div class="stat-card">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-emerald-500">mark_email_read</span>
            <span class="font-medium">Email App</span>
          </div>
          <div class="stat-value mt-2 text-emerald-600 dark:text-emerald-400">{{ emailAppStats.users }}</div>
          <div class="text-xs text-surface-500 mt-1 space-y-0.5">
            <div>{{ emailAppStats.driveUsedHuman }} drive</div>
            <div v-if="emailAppStats.totalLinkedAccounts > 0">{{ emailAppStats.totalLinkedAccounts }} linked</div>
          </div>
        </div>
      </div>

    </div>

    <!-- ===================== MIGRATION TAB ===================== -->
    <div v-if="activeTab === 'migration'" class="space-y-6">
      <div class="flex items-center justify-between gap-2 flex-wrap">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-primary-500">swap_horiz</span>
          <h2 class="text-lg font-semibold">Migration</h2>
        </div>
        <div class="flex gap-2">
          <button @click="loadMigrationTab(true)" class="btn-secondary" :disabled="mailMigrationsLoading || davImportsLoading">
            <span class="material-symbols-rounded" :class="(mailMigrationsLoading || davImportsLoading) && 'animate-spin'">refresh</span>
            Refresh
          </button>
          <button @click="openMigratePopup" class="btn-primary">
            <span class="material-symbols-rounded">move_to_inbox</span>
            Migrate emails
          </button>
        </div>
      </div>

      <div class="flex flex-col lg:flex-row gap-6 items-start">
        <!-- Main column: email migration jobs + contacts/calendar import -->
        <div class="w-full lg:flex-1 min-w-0 space-y-6">
          <!-- Email Migrations: absolute counts/sizes + verified result -->
          <div class="card p-4">
        <div class="flex items-center justify-between mb-4">
          <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500" :class="{ 'animate-spin': runningCount > 0 }">sync</span>
            <h3 class="font-semibold">Email Migrations</h3>
            <span v-if="runningCount > 0" class="px-2 py-0.5 text-xs font-medium rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400">
              {{ runningCount }} active
            </span>
          </div>
          <div class="flex gap-2">
            <button
              v-if="finishedMigrationsCount > 0"
              @click="clearFinishedMigrations"
              class="btn-ghost btn-sm text-surface-500"
              :disabled="migrationClearing"
              title="Remove all completed, failed and cancelled migrations from the list"
            >
              <span class="material-symbols-rounded" :class="migrationClearing && 'animate-spin'">
                {{ migrationClearing ? 'progress_activity' : 'cleaning_services' }}
              </span>
              Clear finished ({{ finishedMigrationsCount }})
            </button>
            <button @click="fetchMigrations" class="btn-ghost btn-sm" :disabled="mailMigrationsLoading">
              <span class="material-symbols-rounded" :class="mailMigrationsLoading && 'animate-spin'">refresh</span>
            </button>
          </div>
        </div>

        <div v-if="mailMigrations.length === 0" class="text-center py-8 text-surface-500">
          <span class="material-symbols-rounded text-4xl mb-2 block">check_circle</span>
          <p>No migrations yet</p>
        </div>

        <div v-else class="space-y-3">
          <div v-for="m in mailMigrations" :key="m.id" class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
            <div class="flex items-center justify-between mb-3 gap-2 flex-wrap">
              <div class="flex items-center gap-3 min-w-0">
                <span
                  class="material-symbols-rounded"
                  :class="m.status === 'running'
                    ? 'text-primary-500 animate-spin'
                    : m.status === 'completed'
                      ? 'text-green-500'
                      : m.status === 'failed'
                        ? 'text-red-500'
                        : 'text-surface-400'"
                >
                  {{ m.status === 'completed' ? 'check_circle'
                    : m.status === 'failed' ? 'error'
                    : m.status === 'cancelled' ? 'cancel'
                    : m.status === 'pending' ? 'schedule'
                    : 'progress_activity' }}
                </span>
                <div class="min-w-0">
                  <p class="font-medium truncate">
                    {{ m.current_account
                      || (m.total_accounts > 1 ? `${m.total_accounts} mailboxes` : 'Migration')
                      || 'Starting…' }}
                  </p>
                  <p class="text-xs text-surface-500 truncate">
                    from {{ m.source_host || '—' }} → {{ m.dest_host || '—' }}
                  </p>
                </div>
              </div>
              <div class="flex items-center gap-1.5 flex-wrap">
                <span
                  v-if="m.migration_mode && m.migration_mode !== 'initial'"
                  class="px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400 capitalize"
                >
                  {{ m.migration_mode }}
                </span>
                <span
                  v-if="Number(m.verified) === 1"
                  class="px-2 py-0.5 text-xs font-medium rounded-full inline-flex items-center gap-1 bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400"
                >
                  <span class="material-symbols-rounded text-sm">verified</span>
                  Verified
                </span>
                <span
                  class="px-2 py-0.5 text-xs font-medium rounded-full capitalize"
                  :class="m.status === 'completed'
                    ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400'
                    : m.status === 'failed'
                      ? 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400'
                      : 'bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-300'"
                >
                  {{ m.status }}
                </span>
                <button @click="openMigrationDetail(m)" class="btn-secondary btn-sm">
                  <span class="material-symbols-rounded">terminal</span>
                  Logs
                </button>
                <button
                  v-if="m.status === 'running' || m.status === 'pending'"
                  @click="cancelMigration(m.id)"
                  class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                  title="Cancel migration"
                >
                  <span class="material-symbols-rounded">cancel</span>
                </button>
                <button
                  v-if="['completed', 'failed', 'cancelled'].includes(m.status)"
                  @click="deleteMigration(m.id)"
                  :disabled="migrationDeleting.has(m.id)"
                  class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                  title="Remove from list"
                >
                  <span class="material-symbols-rounded" :class="migrationDeleting.has(m.id) && 'animate-spin'">
                    {{ migrationDeleting.has(m.id) ? 'progress_activity' : 'delete' }}
                  </span>
                </button>
              </div>
            </div>
            <div class="w-full bg-surface-200 dark:bg-surface-700 rounded-full h-2.5">
              <div
                class="h-2.5 rounded-full transition-all duration-500"
                :class="Number(m.verified) === 1
                  ? 'bg-gradient-to-r from-green-500 to-green-400'
                  : 'bg-gradient-to-r from-primary-500 to-primary-400'"
                :style="{ width: `${m.progress ?? 0}%` }"
              ></div>
            </div>
            <div class="flex items-center justify-between mt-2 text-xs text-surface-500 gap-2 flex-wrap">
              <span>
                {{ formatNumber(m.transferred_messages) }} / {{ formatNumber(m.total_messages) }} emails copied
                · {{ formatBytes(m.transferred_bytes) }}
              </span>
              <span>{{ m.completed_accounts ?? 0 }} / {{ m.total_accounts ?? 0 }} accounts</span>
            </div>
            <p v-if="m.error_message" class="text-xs text-red-500 mt-1 truncate">
              {{ m.error_message }}
            </p>

            <!-- Delta-sync scheduler -->
            <div class="mt-3 pt-3 border-t border-surface-200 dark:border-surface-700">
              <div class="flex items-center justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-3 min-w-0">
                  <Toggle
                    :model-value="Number(m.schedule_enabled) === 1"
                    @update:model-value="(v) => setMigrationSchedule(m, v)"
                  />
                  <div class="min-w-0">
                    <p class="text-sm font-medium">Auto delta sync</p>
                    <p class="text-xs text-surface-500 truncate">
                      <template v-if="Number(m.schedule_enabled) === 1">
                        Next run {{ formatSchedTime(m.next_run_at) }}
                      </template>
                      <template v-else>
                        Keeps the new server topped up until cutover
                      </template>
                    </p>
                  </div>
                </div>
                <select
                  :value="Number(m.delta_interval_minutes) || 360"
                  @change="(e) => updateMigrationInterval(m, e.target.value)"
                  class="input input-sm w-auto"
                >
                  <option v-for="opt in intervalOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
              </div>
              <div class="flex items-center gap-2 mt-2 flex-wrap">
                <button
                  @click="runMigrationDelta(m)"
                  class="btn-secondary btn-sm"
                  :disabled="m.status === 'running' || m.status === 'pending' || migrationBusy.has(m.id)"
                >
                  <span class="material-symbols-rounded">sync</span>
                  Run delta now
                </button>
                <button
                  @click="finalizeMigration(m)"
                  class="btn-primary btn-sm"
                  :disabled="m.status === 'running' || m.status === 'pending'"
                >
                  <span class="material-symbols-rounded">flag</span>
                  Final cutover
                </button>
                <span v-if="m.sweep_at" class="text-xs text-amber-600 dark:text-amber-400 inline-flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm">schedule</span>
                  Sweep {{ formatSchedTime(m.sweep_at) }}
                </span>
                <span v-else-if="m.last_delta_at" class="text-xs text-surface-400 inline-flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm">history</span>
                  Last delta {{ formatSchedTime(m.last_delta_at) }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ============ IMPORT card: old provider file -> FlowOne user ============ -->
      <div class="card p-4 border-l-4 !border-l-primary-500">
        <div class="flex items-center justify-between mb-4 gap-2 flex-wrap">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center flex-shrink-0">
              <span class="material-symbols-rounded text-primary-500">cloud_upload</span>
            </div>
            <div>
              <h3 class="font-semibold">Import Contacts &amp; Calendar</h3>
              <p class="text-xs text-surface-400">Into FlowOne — upload files exported from the old provider</p>
            </div>
          </div>
          <button @click="fetchDavImports" class="btn-ghost btn-sm" :disabled="davImportsLoading" title="Refresh recent imports">
            <span class="material-symbols-rounded" :class="davImportsLoading && 'animate-spin'">refresh</span>
          </button>
        </div>

        <!-- Single vs Batch mode (segmented, not a checkbox) -->
        <div class="inline-flex rounded-xl bg-surface-100 dark:bg-surface-800 p-1 mb-4">
          <button
            type="button"
            @click="setDavMode('single')"
            :class="['px-4 py-1.5 rounded-lg text-sm font-medium flex items-center gap-1.5 transition',
              davMode === 'single' ? 'bg-white dark:bg-surface-700 shadow text-primary-600 dark:text-primary-400' : 'text-surface-500']"
          >
            <span class="material-symbols-rounded text-base">person</span> Single
          </button>
          <button
            type="button"
            @click="setDavMode('batch')"
            :class="['px-4 py-1.5 rounded-lg text-sm font-medium flex items-center gap-1.5 transition',
              davMode === 'batch' ? 'bg-white dark:bg-surface-700 shadow text-primary-600 dark:text-primary-400' : 'text-surface-500']"
          >
            <span class="material-symbols-rounded text-base">group</span> Batch
          </button>
        </div>

        <!-- ===== SINGLE ===== -->
        <div v-if="davMode === 'single'">
        <p class="text-sm text-surface-500 mb-4">
          Upload a contacts file (<code>.vcf</code> / <code>.csv</code>) or a calendar file
          (<code>.ics</code> / <code>.csv</code>) exported from the old provider. It's imported into the
          destination user's FlowOne address book or calendar. Re-running the same file is safe — entries
          are matched by UID and updated in place.
        </p>

        <div class="grid gap-4 md:grid-cols-2">
          <!-- Type selector (segmented, not a checkbox) -->
          <div>
            <label class="block text-sm font-medium mb-1.5">What are you importing?</label>
            <div class="inline-flex rounded-xl bg-surface-100 dark:bg-surface-800 p-1">
              <button
                type="button"
                @click="davForm.type = 'contacts'"
                :class="['px-4 py-1.5 rounded-lg text-sm font-medium flex items-center gap-1.5 transition',
                  davForm.type === 'contacts' ? 'bg-white dark:bg-surface-700 shadow text-primary-600 dark:text-primary-400' : 'text-surface-500']"
              >
                <span class="material-symbols-rounded text-base">contacts</span> Contacts
              </button>
              <button
                type="button"
                @click="davForm.type = 'calendar'"
                :class="['px-4 py-1.5 rounded-lg text-sm font-medium flex items-center gap-1.5 transition',
                  davForm.type === 'calendar' ? 'bg-white dark:bg-surface-700 shadow text-primary-600 dark:text-primary-400' : 'text-surface-500']"
              >
                <span class="material-symbols-rounded text-base">calendar_month</span> Calendar
              </button>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium mb-1.5">Destination user</label>
            <select v-model="davForm.userEmail" class="input">
              <option value="" disabled>
                {{ davAccountEmails.length ? 'Select a user…' : 'Loading users…' }}
              </option>
              <option v-for="email in davAccountEmails" :key="email" :value="email">
                {{ email }}
              </option>
            </select>
          </div>
        </div>

        <div class="mt-4">
          <label class="block text-sm font-medium mb-1.5">
            File
            <span class="text-surface-400 font-normal">
              ({{ davForm.type === 'calendar' ? '.ics or .csv' : '.vcf or .csv' }})
            </span>
          </label>
          <div class="flex items-center gap-3 flex-wrap">
            <label class="btn-secondary btn-sm cursor-pointer">
              <span class="material-symbols-rounded">upload_file</span>
              Choose file
              <input
                type="file"
                class="hidden"
                :accept="davForm.type === 'calendar' ? '.ics,.ical,.csv' : '.vcf,.vcard,.csv'"
                @change="onDavFileChange"
              />
            </label>
            <span v-if="davForm.fileName" class="text-sm text-surface-600 dark:text-surface-300 inline-flex items-center gap-1">
              <span class="material-symbols-rounded text-base text-green-500">description</span>
              {{ davForm.fileName }}
            </span>
          </div>
        </div>

        <div class="mt-4 flex justify-end">
          <button @click="submitDavImport" class="btn-primary" :disabled="davSubmitting">
            <span class="material-symbols-rounded" :class="davSubmitting && 'animate-spin'">
              {{ davSubmitting ? 'progress_activity' : 'cloud_upload' }}
            </span>
            {{ davSubmitting ? 'Importing…' : 'Import' }}
          </button>
        </div>
        </div>
        <!-- /SINGLE -->

        <!-- ===== BATCH ===== -->
        <div v-else class="space-y-4">
          <p class="text-sm text-surface-500">
            Upload many files at once — one per user. Each filename (without extension) becomes the mailbox:
            <code>robert.fekete.csv</code> &rarr; <code>robert.fekete@{{ davBatchDomain || 'domain' }}</code>.
            Type is auto-detected: <code>.ics</code> &rarr; calendar, <code>.vcf</code>/<code>.csv</code> &rarr; contacts.
            Re-running is safe — entries are matched by UID.
          </p>

          <div class="grid gap-4 md:grid-cols-2">
            <div>
              <label class="block text-sm font-medium mb-1.5">Domain</label>
              <input v-model="davBatchDomain" type="text" class="input" placeholder="yourdomain.com" />
              <p class="text-xs text-surface-400 mt-1">
                Used to build each email from its filename. Filenames that are already full emails are used as-is.
              </p>
            </div>
            <div>
              <label class="block text-sm font-medium mb-1.5">
                Files <span class="text-surface-400 font-normal">(.vcf, .csv, .ics — select many)</span>
              </label>
              <label class="btn-secondary btn-sm cursor-pointer">
                <span class="material-symbols-rounded">upload_file</span>
                Choose files
                <input
                  type="file"
                  class="hidden"
                  multiple
                  accept=".ics,.ical,.vcf,.vcard,.csv"
                  @change="onDavBatchFiles"
                />
              </label>
            </div>
          </div>

          <!-- Preview / per-row results -->
          <div v-if="davBatchRows.length" class="border border-surface-200 dark:border-surface-700 rounded-xl overflow-hidden">
            <div class="max-h-72 overflow-y-auto divide-y divide-surface-100 dark:divide-surface-800">
              <div
                v-for="(row, idx) in davBatchRows"
                :key="idx"
                class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
              >
                <div class="flex items-center gap-2 min-w-0">
                  <span
                    class="material-symbols-rounded text-base flex-shrink-0"
                    :class="row.status === 'completed' ? 'text-green-500'
                      : (row.status === 'failed' || row.status === 'error') ? 'text-red-500'
                      : row.status === 'importing' ? 'text-primary-500 animate-spin'
                      : 'text-surface-400'"
                  >
                    {{ row.status === 'completed' ? 'check_circle'
                      : (row.status === 'failed' || row.status === 'error') ? 'error'
                      : row.status === 'importing' ? 'progress_activity'
                      : (row.type === 'calendar' ? 'calendar_month' : 'contacts') }}
                  </span>
                  <div class="min-w-0">
                    <div class="truncate font-mono text-xs text-surface-400">{{ row.fileName }}</div>
                    <div class="truncate">{{ row.email || '— unresolved —' }}</div>
                  </div>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                  <span v-if="row.type" class="text-xs px-1.5 py-0.5 rounded-full bg-surface-100 dark:bg-surface-800 capitalize">
                    {{ row.type }}
                  </span>
                  <span v-if="row.status === 'completed' && row.counts" class="text-xs text-surface-500 whitespace-nowrap">
                    {{ formatNumber(row.counts.imported) }} new · {{ formatNumber(row.counts.updated) }} upd
                  </span>
                  <span
                    v-else-if="row.status === 'failed' || row.status === 'error'"
                    class="text-xs text-red-500 truncate max-w-[180px]"
                  >{{ row.error }}</span>
                  <button
                    v-if="!davBatchRunning"
                    @click="removeDavBatchRow(idx)"
                    class="btn-ghost btn-sm text-surface-400 hover:text-red-500 p-1"
                  >
                    <span class="material-symbols-rounded text-base">close</span>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Progress + actions -->
          <div v-if="davBatchRows.length" class="space-y-2">
            <div v-if="davBatchRunning || davBatchProcessed > 0" class="space-y-1">
              <div class="flex justify-between text-xs text-surface-500">
                <span>{{ davBatchProcessed }} / {{ davBatchRows.length }} processed</span>
                <span v-if="davBatchErrorCount > 0" class="text-red-500">{{ davBatchErrorCount }} error(s)</span>
              </div>
              <div class="w-full bg-surface-200 dark:bg-surface-700 rounded-full h-2">
                <div
                  class="bg-gradient-to-r from-primary-500 to-emerald-500 h-2 rounded-full transition-all duration-300"
                  :style="{ width: `${davBatchRows.length ? Math.round(davBatchProcessed / davBatchRows.length * 100) : 0}%` }"
                ></div>
              </div>
            </div>
            <div class="flex justify-end gap-2">
              <button @click="clearDavBatch" class="btn-secondary btn-sm" :disabled="davBatchRunning">Clear</button>
              <button @click="runDavBatch" class="btn-primary" :disabled="davBatchRunning || davBatchReadyCount === 0">
                <span class="material-symbols-rounded" :class="davBatchRunning && 'animate-spin'">
                  {{ davBatchRunning ? 'progress_activity' : 'cloud_upload' }}
                </span>
                {{ davBatchRunning ? 'Importing…' : `Import all (${davBatchReadyCount})` }}
              </button>
            </div>
          </div>
        </div>
        <!-- /BATCH -->

        <!-- Recent imports -->
        <div v-if="davImports.length > 0" class="mt-5 border-t border-surface-200 dark:border-surface-700 pt-4">
          <div class="flex items-center justify-between gap-2 mb-2">
            <p class="text-xs font-medium text-surface-500 uppercase tracking-wide">
              Recent imports ({{ davImports.length }})
            </p>
            <button
              @click="clearDavImports"
              class="btn-ghost btn-sm text-surface-400 hover:text-red-500"
              :disabled="davImportsClearing"
            >
              <span class="material-symbols-rounded text-base" :class="davImportsClearing && 'animate-spin'">
                {{ davImportsClearing ? 'progress_activity' : 'delete_sweep' }}
              </span>
              Clear all
            </button>
          </div>
          <!-- ~10 rows visible, then scroll -->
          <div class="space-y-2 max-h-[440px] overflow-y-auto pr-1">
          <div
            v-for="d in davImports"
            :key="d.id"
            class="flex items-center justify-between gap-2 text-sm bg-surface-50 dark:bg-surface-800 rounded-lg px-3 py-2 flex-wrap"
          >
            <div class="flex items-center gap-2 min-w-0">
              <span class="material-symbols-rounded text-base"
                :class="d.status === 'completed' ? 'text-green-500' : 'text-red-500'">
                {{ d.type === 'calendar' ? 'calendar_month' : 'contacts' }}
              </span>
              <span class="truncate">{{ d.user_email }}</span>
              <span class="text-xs text-surface-400 capitalize">· {{ d.type }}</span>
            </div>
            <div class="flex items-center gap-2 text-xs">
              <span v-if="d.status === 'completed'" class="text-surface-500">
                {{ formatNumber(d.imported) }} new · {{ formatNumber(d.updated) }} updated
              </span>
              <span v-else class="text-red-500 truncate max-w-[220px]">{{ d.error_message || 'Failed' }}</span>
            </div>
          </div>
          </div>
        </div>
      </div>

      <!-- ============ EXPORT card: FlowOne user -> file download ============ -->
      <div class="card p-4 border-l-4 !border-l-sky-500">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-10 h-10 rounded-xl bg-sky-500/10 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-rounded text-sky-500">download</span>
          </div>
          <div>
            <h3 class="font-semibold">Export Contacts &amp; Calendar</h3>
            <p class="text-xs text-surface-400">
              Out of FlowOne — download a user's address book (<code>.vcf</code>) / calendar (<code>.ics</code>) for handover or backup
            </p>
          </div>
        </div>
        <div class="flex flex-col sm:flex-row gap-2 sm:items-end">
          <div class="flex-1">
            <label class="block text-sm font-medium mb-1.5">User</label>
            <select v-model="davExportEmail" class="input">
              <option value="" disabled>
                {{ davAccountEmails.length ? 'Select a user…' : 'Loading users…' }}
              </option>
              <option v-for="email in davAccountEmails" :key="email" :value="email">
                {{ email }}
              </option>
            </select>
          </div>
          <div class="flex gap-2 flex-wrap">
            <button
              @click="exportDav('contacts')"
              class="btn-secondary"
              :disabled="davExporting !== '' || !davExportEmail"
            >
              <span class="material-symbols-rounded" :class="davExporting === 'contacts' && 'animate-spin'">
                {{ davExporting === 'contacts' ? 'progress_activity' : 'contacts' }}
              </span>
              Contacts (.vcf)
            </button>
            <button
              @click="exportDav('calendar')"
              class="btn-secondary"
              :disabled="davExporting !== '' || !davExportEmail"
            >
              <span class="material-symbols-rounded" :class="davExporting === 'calendar' && 'animate-spin'">
                {{ davExporting === 'calendar' ? 'progress_activity' : 'calendar_month' }}
              </span>
              Calendar (.ics)
            </button>
            <button
              @click="exportDav('both')"
              class="btn-primary"
              :disabled="davExporting !== '' || !davExportEmail"
            >
              <span class="material-symbols-rounded" :class="davExporting === 'both' && 'animate-spin'">
                {{ davExporting === 'both' ? 'progress_activity' : 'download' }}
              </span>
              Both
            </button>
          </div>
        </div>
      </div>
        </div>

        <!-- Right sidebar: migration readiness board (DB-persisted checklist) -->
        <aside class="w-full lg:w-2/5 lg:max-w-[600px] lg:flex-shrink-0">
          <div class="lg:sticky lg:top-4">
            <MigrationChecklist />
          </div>
        </aside>
      </div>
    </div>

    <!-- ===================== MAIL TAB (accounts list) ===================== -->
    <div v-if="activeTab === 'mail'" class="space-y-6">
      <div class="card p-4">
        <div class="flex flex-wrap gap-4 items-center">
          <div class="relative flex-1 min-w-[200px]">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
            <input
              v-model="mailSearchQuery"
              type="text"
              class="input pl-10"
              placeholder="Search accounts..."
            />
          </div>
          
          <select v-model="mailSelectedDomain" class="input w-auto">
            <option value="all">All Domains ({{ mailDomainList.length }})</option>
            <option v-for="domain in mailDomainList" :key="domain" :value="domain">
              {{ domain }}
            </option>
          </select>
        </div>
      </div>

      <div v-if="mailLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <div v-else class="card overflow-hidden">
        <table class="table">
          <thead>
            <tr class="bg-surface-50 dark:bg-surface-800/50">
              <th class="cursor-pointer select-none" @click="toggleMailSort('email')">
                <div class="flex items-center gap-1">
                  Email
                  <span v-if="mailSortColumn === 'email'" class="material-symbols-rounded text-sm">
                    {{ mailSortOrder === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
                  </span>
                </div>
              </th>
              <th class="cursor-pointer select-none" @click="toggleMailSort('size')">
                <div class="flex items-center gap-1">
                  Mailbox
                  <span v-if="mailSortColumn === 'size'" class="material-symbols-rounded text-sm">
                    {{ mailSortOrder === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
                  </span>
                </div>
              </th>
              <th>Email App</th>
              <th>Forwards To</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="account in filteredMailAccounts" :key="account.email">
              <td>
                <div class="flex items-center gap-3">
                  <div :class="[
                    'w-10 h-10 rounded-xl flex items-center justify-center',
                    account.uses_email_app 
                      ? 'bg-emerald-100 dark:bg-emerald-500/20' 
                      : 'bg-blue-100 dark:bg-blue-500/20'
                  ]">
                    <span :class="[
                      'material-symbols-rounded',
                      account.uses_email_app 
                        ? 'text-emerald-600 dark:text-emerald-400' 
                        : 'text-blue-600 dark:text-blue-400'
                    ]">{{ account.uses_email_app ? 'mark_email_read' : 'person' }}</span>
                  </div>
                  <div>
                    <p class="font-medium">{{ account.email }}</p>
                    <p class="text-xs text-surface-500">{{ account.domain }}</p>
                    <span
                      v-if="account.force_password_change"
                      class="mt-1 inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-400"
                      title="This user must set a new password on their next webmail login"
                    >
                      <span class="material-symbols-rounded text-xs">lock_reset</span>
                      Password change required
                    </span>
                    <span
                      v-if="account.suspended"
                      class="mt-1 inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-400"
                      title="Login is blocked (IMAP/POP3/SMTP/webmail). Incoming mail is still being received."
                    >
                      <span class="material-symbols-rounded text-xs">block</span>
                      Suspended
                    </span>
                  </div>
                </div>
              </td>
              <td>
                <span class="text-surface-600 dark:text-surface-400">{{ account.size_human }}</span>
                <span class="block text-xs text-surface-400">of {{ account.mailbox_quota_human ?? 'Unlimited' }}</span>
              </td>
              <td>
                <div v-if="account.uses_email_app" class="space-y-1.5">
                  <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-sm text-surface-400">folder</span>
                    <span class="text-sm">{{ account.drive_used_human }}</span>
                    <span v-if="account.drive_quota >= 0" class="text-xs text-surface-400">/ {{ account.drive_quota_human }}</span>
                  </div>
                  <div v-if="account.linked_accounts_list?.length > 0" class="flex flex-wrap gap-1">
                    <span 
                      v-for="linked in account.linked_accounts_list" 
                      :key="linked.email"
                      :class="[
                        'text-xs px-2 py-0.5 rounded-full flex items-center gap-1',
                        linked.type === 'oauth' 
                          ? 'bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400' 
                          : 'bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400'
                      ]"
                      :title="linked.email"
                    >
                      <span class="material-symbols-rounded text-xs">{{ linked.type === 'oauth' ? 'passkey' : 'alternate_email' }}</span>
                      {{ linked.name || linked.email }}
                    </span>
                  </div>
                </div>
                <span v-else class="text-surface-400 text-sm">—</span>
              </td>
              <td>
                <div v-if="forwardsBySource[account.email]?.length" class="flex flex-wrap gap-1">
                  <span 
                    v-for="dest in forwardsBySource[account.email]" 
                    :key="dest"
                    class="text-xs px-2 py-1 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400"
                  >
                    {{ dest }}
                  </span>
                </div>
                <span v-else class="text-surface-400">—</span>
              </td>
              <td class="text-right">
                <div class="flex justify-end gap-1">
                  <button 
                    @click="openMailRedirectModal(account.email)"
                    class="btn-ghost btn-sm"
                    title="Manage Redirects"
                  >
                    <span class="material-symbols-rounded">forward_to_inbox</span>
                  </button>
                  <button 
                    @click="toggleForcePasswordChange(account)"
                    class="btn-ghost btn-sm"
                    :class="account.force_password_change ? 'text-amber-600 dark:text-amber-400' : ''"
                    :disabled="forcePwChangePending === account.email"
                    :title="account.force_password_change ? 'Cancel forced password change on next login' : 'Require password change on next login'"
                  >
                    <span class="material-symbols-rounded" :class="forcePwChangePending === account.email && 'animate-spin'">
                      {{ forcePwChangePending === account.email ? 'progress_activity' : 'lock_reset' }}
                    </span>
                  </button>
                  <AccountAdminMenu
                    :account="account"
                    :suspend-pending="suspendPending === account.email"
                    @reset-password="(a) => { showMailResetPassword = false; mailResetPasswordModal = { show: true, account: a } }"
                    @suspend="openSuspendModal"
                    @resume="resumeMailAccount"
                    @delete="(a) => mailDeleteModal = { show: true, type: 'account', item: a }"
                    @changed="fetchMailAccounts(true)"
                  />
                </div>
              </td>
            </tr>
            <tr v-if="!filteredMailAccounts.length">
              <td colspan="5" class="py-12 text-center text-surface-400">
                <span class="material-symbols-rounded text-4xl mb-2 block">mail</span>
                No accounts found
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- DNS Tab -->
    <div v-if="activeTab === 'dns'" class="space-y-6">
      <div class="flex justify-between items-center">
        <span v-if="getCacheAge(CACHE_KEYS.DNS_ZONES) !== 'not cached'" class="text-xs text-surface-400">
          <span class="material-symbols-rounded text-sm align-middle">schedule</span>
          Updated {{ getCacheAge(CACHE_KEYS.DNS_ZONES) }}
        </span>
        <span v-else></span>
        <div class="flex items-center gap-3">
          <button @click="loadDnsTab(true)" class="btn-secondary" :disabled="dnsLoading">
            <span class="material-symbols-rounded" :class="dnsLoading && 'animate-spin'">refresh</span>
            Refresh
          </button>
          <StatusBadge :status="dnsStatus?.running ? 'running' : 'stopped'" />
          <span class="text-sm text-surface-500">{{ dnsStatus?.zone_count || 0 }} zones</span>
        </div>
      </div>

      <div v-if="dnsLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <div v-else class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <div class="card">
          <div class="card-header">
            <h3 class="font-medium">Zones</h3>
          </div>
          <div class="divide-y divide-surface-100 dark:divide-surface-800">
            <button
              v-for="zone in dnsZones"
              :key="zone.id"
              @click="selectDnsZone(zone)"
              :class="[
                'w-full px-4 py-3 flex items-center justify-between text-left transition-colors',
                dnsSelectedZone?.id === zone.id 
                  ? 'bg-primary-50 dark:bg-primary-500/10' 
                  : 'hover:bg-surface-50 dark:hover:bg-surface-800'
              ]"
            >
              <div>
                <p class="font-medium">{{ zone.name }}</p>
                <p class="text-sm text-surface-500">{{ zone.record_count }} records</p>
              </div>
              <span class="material-symbols-rounded text-surface-400">chevron_right</span>
            </button>
            <div v-if="!dnsZones.length" class="px-4 py-8 text-center text-surface-400">
              No zones
            </div>
          </div>
        </div>

        <div class="lg:col-span-3">
          <template v-if="dnsSelectedZone">
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <h3 class="font-medium">{{ dnsSelectedZone.name }}</h3>
                <div class="flex gap-2">
                  <button 
                    @click="checkDnsIssues" 
                    class="btn-secondary btn-sm"
                    :disabled="dnsFixingIssues"
                  >
                    <span v-if="dnsFixingIssues" class="spinner mr-1"></span>
                    <span v-else class="material-symbols-rounded mr-1">search</span>
                    Check Issues
                  </button>
                  <button @click="dnsCreateRecordModal = true" class="btn-primary btn-sm">
                    <span class="material-symbols-rounded">add</span>
                    Add Record
                  </button>
                </div>
              </div>
              
              <!-- DNS Issues Alert -->
              <div v-if="dnsIssuesResult" class="mx-4 mt-4 p-4 rounded-lg" :class="dnsIssuesResult.issues_found > 0 ? 'bg-yellow-500/10 border border-yellow-500/30' : 'bg-green-500/10 border border-green-500/30'">
                <div class="flex items-start gap-3">
                  <span class="material-symbols-rounded" :class="dnsIssuesResult.issues_found > 0 ? 'text-yellow-500' : 'text-green-500'">
                    {{ dnsIssuesResult.issues_found > 0 ? 'warning' : 'check_circle' }}
                  </span>
                  <div class="flex-1">
                    <p class="font-medium" :class="dnsIssuesResult.issues_found > 0 ? 'text-yellow-500' : 'text-green-500'">
                      {{ dnsIssuesResult.fixed?.length > 0 
                          ? `Fixed ${dnsIssuesResult.fixed.length} issue(s)!` 
                          : (dnsIssuesResult.issues_found > 0 
                              ? `Found ${dnsIssuesResult.issues_found} issue(s)` 
                              : 'No DNS issues found!') }}
                    </p>
                    
                    <!-- Show issues that need fixing -->
                    <div v-if="dnsIssuesResult.issues?.length > 0 && !dnsIssuesResult.fixed?.length" class="mt-2 text-sm text-surface-600 dark:text-surface-400">
                      <ul class="list-disc list-inside">
                        <li v-for="(issue, i) in dnsIssuesResult.issues" :key="i">{{ issue }}</li>
                      </ul>
                      <button 
                        @click="fixDnsIssues" 
                        class="btn-primary btn-sm mt-3"
                        :disabled="dnsFixingIssues"
                      >
                        <span v-if="dnsFixingIssues" class="spinner mr-1"></span>
                        <span v-else class="material-symbols-rounded mr-1">build</span>
                        Fix All Issues
                      </button>
                    </div>
                    
                    <!-- Show what was fixed -->
                    <div v-if="dnsIssuesResult.fixed?.length > 0" class="mt-2 text-sm text-surface-600 dark:text-surface-400">
                      <ul class="list-disc list-inside">
                        <li v-for="(fix, i) in dnsIssuesResult.fixed" :key="i">{{ fix }}</li>
                      </ul>
                    </div>
                  </div>
                  <button @click="dnsIssuesResult = null" class="btn-ghost btn-sm">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
              </div>
              
              <div class="overflow-x-auto">
                <table class="table">
                  <thead>
                    <tr class="bg-surface-50 dark:bg-surface-800/50">
                      <th>Name</th>
                      <th>Type</th>
                      <th>Content</th>
                      <th>TTL</th>
                      <th>Priority</th>
                      <th class="text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="record in dnsRecords" :key="record.id">
                      <td class="font-mono text-sm">{{ record.name }}</td>
                      <td><span class="badge badge-info">{{ record.type }}</span></td>
                      <td class="font-mono text-sm max-w-xs truncate" :title="record.content">
                        {{ record.content }}
                      </td>
                      <td class="text-surface-500">{{ record.ttl }}</td>
                      <td class="text-surface-500 text-center">
                        <span v-if="record.type === 'MX' || record.type === 'SRV'">{{ record.prio ?? record.priority ?? '-' }}</span>
                        <span v-else class="text-surface-400">-</span>
                      </td>
                      <td class="text-right">
                        <button
                          v-if="record.type !== 'SOA'"
                          @click="openEditDnsRecord(record)"
                          class="btn-ghost btn-sm text-blue-500"
                          title="Edit"
                        >
                          <span class="material-symbols-rounded">edit</span>
                        </button>
                        <button
                          v-if="record.type !== 'SOA'"
                          @click="dnsDeleteModal = { show: true, record }"
                          class="btn-ghost btn-sm text-red-500"
                          title="Delete"
                        >
                          <span class="material-symbols-rounded">delete</span>
                        </button>
                      </td>
                    </tr>
                    <tr v-if="!dnsRecords.length">
                      <td colspan="5" class="py-8 text-center text-surface-400">
                        No records
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </template>

          <div v-else class="card p-12 text-center text-surface-400">
            <span class="material-symbols-rounded text-4xl mb-2 block">dns</span>
            Select a zone to view records
          </div>
        </div>
      </div>
    </div>

    <!-- WordPress Tab -->
    <div v-if="activeTab === 'wordpress'" class="space-y-6">
      <!-- Header with refresh -->
      <div class="flex justify-between items-center">
        <span v-if="getCacheAge(CACHE_KEYS.WORDPRESS) !== 'not cached'" class="text-xs text-surface-400">
          <span class="material-symbols-rounded text-sm align-middle">schedule</span>
          Updated {{ getCacheAge(CACHE_KEYS.WORDPRESS) }}
        </span>
        <span v-else></span>
        <button @click="fetchWpData(true)" class="btn-secondary" :disabled="wpLoading">
          <span class="material-symbols-rounded" :class="wpLoading && 'animate-spin'">refresh</span>
          Refresh
        </button>
      </div>
      
      <div v-if="wpLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <template v-else>
        <!-- Install New Application -->
        <div class="card">
          <div class="card-header">
            <h3 class="font-medium flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">add_circle</span>
              Install New Application
            </h3>
          </div>
          <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
              <div
                v-for="template in wpTemplates"
                :key="template.slug"
                class="group relative bg-surface-50 dark:bg-surface-800/50 rounded-xl p-5 border border-surface-200 dark:border-surface-700 hover:border-primary-500 dark:hover:border-primary-500 transition-all cursor-pointer"
                @click="openWpInstallModal(template)"
              >
                <div class="flex items-start gap-4">
                  <div class="w-12 h-12 rounded-xl bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
                    <span class="material-symbols-rounded text-2xl text-primary-600 dark:text-primary-400">
                      {{ getWpAppIcon(template) }}
                    </span>
                  </div>
                  <div class="flex-1 min-w-0">
                    <h3 class="font-semibold group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
                      {{ template.name }}
                    </h3>
                    <p class="text-sm text-surface-500 mt-1 line-clamp-2">{{ template.description }}</p>
                    <span class="inline-flex items-center px-2 py-0.5 mt-2 rounded-full text-xs font-medium bg-surface-200 dark:bg-surface-700">
                      {{ template.category }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
            <p v-if="wpTemplates.length === 0" class="text-center text-surface-500 py-8">
              No application templates available.
            </p>
          </div>
        </div>

        <!-- Installed Applications -->
        <div class="card">
          <div class="card-header flex items-center justify-between">
            <h3 class="font-medium flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">deployed_code</span>
              Installed Applications
              <span class="ml-2 px-2 py-0.5 text-xs rounded-full bg-surface-200 dark:bg-surface-700">
                {{ wpFilteredApps.length }}
              </span>
            </h3>
            <div class="flex items-center gap-3">
              <div class="relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
                <input v-model="wpSearchQuery" type="text" placeholder="Search..." class="input pl-10 w-48" />
              </div>
              <select v-model="wpFilterSite" class="input w-48">
                <option value="">All Sites</option>
                <option v-for="domain in wpUniqueSites" :key="domain" :value="domain">{{ domain }}</option>
              </select>
            </div>
          </div>
          
          <div class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr class="bg-surface-50 dark:bg-surface-800/50">
                  <th>Application</th>
                  <th>Site</th>
                  <th>Version</th>
                  <th>Status</th>
                  <th>Installed</th>
                  <th class="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="app in wpFilteredApps" :key="app.id">
                  <td>
                    <div class="flex items-center gap-3">
                      <div class="w-10 h-10 rounded-lg bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
                        <span class="material-symbols-rounded text-primary-600 dark:text-primary-400">
                          {{ getWpAppIcon({ slug: app.app_slug }) }}
                        </span>
                      </div>
                      <div>
                        <div class="font-medium">{{ app.app_name || app.app_slug }}</div>
                        <div class="text-sm text-surface-500">{{ app.install_path }}</div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <router-link :to="`/sites-v2/${app.domain}/manage?tab=wordpress`" class="text-primary-600 dark:text-primary-400 hover:underline">
                      {{ app.domain }}
                    </router-link>
                  </td>
                  <td class="text-surface-600 dark:text-surface-300">
                    {{ wpSummaries[app.domain]?.version || app.app_version || 'latest' }}
                  </td>
                  <td>
                    <div v-if="app.app_slug === 'wordpress' && wpSummaries[app.domain]" class="flex flex-wrap gap-1">
                      <span class="text-xs px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-500/20 text-blue-600">
                        {{ wpSummaries[app.domain].posts?.post?.count || 0 }} posts
                      </span>
                      <span class="text-xs px-2 py-0.5 rounded-full bg-purple-100 dark:bg-purple-500/20 text-purple-600">
                        {{ wpSummaries[app.domain].posts?.page?.count || 0 }} pages
                      </span>
                      <span v-if="wpSummaries[app.domain].core_updates?.length" class="text-xs px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-500/20 text-red-600">
                        core update
                      </span>
                      <span v-if="wpSummaries[app.domain].plugins?.updates_available" class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600">
                        {{ wpSummaries[app.domain].plugins.updates_available }} plugin{{ wpSummaries[app.domain].plugins.updates_available > 1 ? 's' : '' }}
                      </span>
                    </div>
                    <div v-else-if="app.app_slug === 'wordpress' && wpSummaryLoading[app.domain]" class="flex items-center">
                      <span class="spinner-sm"></span>
                    </div>
                    <StatusBadge v-else :status="app.status" />
                  </td>
                  <td class="text-sm text-surface-500">{{ formatWpDate(app.installed_at) }}</td>
                  <td class="text-right">
                    <div class="flex items-center justify-end gap-1">
                      <!-- Update Button (opens modal) -->
                      <button 
                        v-if="app.app_slug === 'wordpress' && wpHasUpdates(app.domain)"
                        @click="openWpUpdateModal(app.domain)"
                        class="btn-ghost btn-sm text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-500/10"
                        :disabled="wpUpdating[app.domain]"
                        title="Choose what to update"
                      >
                        <span v-if="wpUpdating[app.domain]" class="spinner-sm"></span>
                        <span v-else class="material-symbols-rounded">system_update_alt</span>
                        <span class="text-xs">{{ wpTotalUpdates(app.domain) }}</span>
                      </button>
                      <router-link v-if="app.app_slug === 'wordpress'" :to="`/sites-v2/${app.domain}/manage?tab=wordpress`" class="btn-ghost btn-sm" title="Manage">
                        <span class="material-symbols-rounded">settings</span>
                      </router-link>
                      <a v-if="app.admin_url" :href="app.admin_url" target="_blank" class="btn-ghost btn-sm" title="Open Admin">
                        <span class="material-symbols-rounded">admin_panel_settings</span>
                      </a>
                      <button @click="openWpUninstallModal(app)" class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10" title="Uninstall">
                        <span class="material-symbols-rounded">delete</span>
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="wpFilteredApps.length === 0">
                  <td colspan="6" class="py-12 text-center text-surface-400">
                    <span class="material-symbols-rounded text-4xl mb-2 block">apps</span>
                    No applications installed yet
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>

    <!-- Docker Tab -->
    <div v-if="activeTab === 'docker'" class="space-y-6">
      <!-- Header with refresh -->
      <div class="flex justify-between items-center">
        <span v-if="getCacheAge(CACHE_KEYS.DOCKER_STATUS) !== 'not cached'" class="text-xs text-surface-400">
          <span class="material-symbols-rounded text-sm align-middle">schedule</span>
          Updated {{ getCacheAge(CACHE_KEYS.DOCKER_STATUS) }}
        </span>
        <span v-else></span>
        <button @click="fetchDockerStatus(true)" class="btn-secondary" :disabled="dockerLoading">
          <span class="material-symbols-rounded" :class="dockerLoading && 'animate-spin'">refresh</span>
          Refresh
        </button>
      </div>
      
      <div v-if="dockerLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <template v-else>
        <!-- Docker Not Installed -->
        <div v-if="!dockerStatus?.installed" class="card p-12 text-center">
          <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-3xl text-blue-600 dark:text-blue-400">deployed_code</span>
          </div>
          <h3 class="text-xl font-semibold mb-2">Docker Not Installed</h3>
          <p class="text-surface-500 mb-6 max-w-md mx-auto">
            Docker is not installed on this server. Install Docker to run containerized applications.
          </p>
          <button @click="installDocker" class="btn-primary" :disabled="dockerInstalling">
            <span v-if="dockerInstalling" class="spinner-sm mr-2"></span>
            <span class="material-symbols-rounded">download</span>
            {{ dockerInstalling ? 'Installing...' : 'Install Docker' }}
          </button>
        </div>

        <!-- Docker Installed -->
        <template v-else>
          <!-- Docker Status -->
          <div class="card p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="font-semibold flex items-center gap-2">
                <span class="material-symbols-rounded text-blue-500">deployed_code</span>
                Docker Status
              </h3>
              <button @click="fetchDockerStatus" class="btn-secondary btn-sm">
                <span class="material-symbols-rounded">refresh</span>
              </button>
            </div>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
                <p class="text-xs text-surface-500 mb-1">Status</p>
                <div class="flex items-center gap-2">
                  <span :class="['w-2 h-2 rounded-full', dockerStatus.running ? 'bg-green-500' : 'bg-red-500']"></span>
                  <span class="font-semibold">{{ dockerStatus.running ? 'Running' : 'Stopped' }}</span>
                </div>
              </div>
              <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
                <p class="text-xs text-surface-500 mb-1">Version</p>
                <p class="font-semibold">{{ dockerStatus.version || '-' }}</p>
              </div>
              <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
                <p class="text-xs text-surface-500 mb-1">Compose</p>
                <p class="font-semibold">{{ dockerStatus.compose_installed ? dockerStatus.compose_version : 'Not Installed' }}</p>
              </div>
              <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
                <p class="text-xs text-surface-500 mb-1">Containers</p>
                <p class="font-semibold">{{ dockerContainers.length }}</p>
              </div>
            </div>
          </div>

          <!-- Containers -->
          <div class="card">
            <div class="card-header flex items-center justify-between">
              <h3 class="font-semibold flex items-center gap-2">
                <span class="material-symbols-rounded text-green-500">view_in_ar</span>
                Containers
                <span class="text-xs px-2 py-0.5 rounded-full bg-surface-200 dark:bg-surface-700">
                  {{ dockerContainers.length }}
                </span>
              </h3>
            </div>

            <div v-if="dockerContainers.length === 0" class="p-12 text-center text-surface-500">
              <span class="material-symbols-rounded text-4xl mb-2 block">inbox</span>
              <p>No containers found</p>
              <p class="text-sm mt-1">Start a container using docker-compose or docker run</p>
            </div>

            <div v-else class="overflow-x-auto">
              <table class="table">
                <thead>
                  <tr class="bg-surface-50 dark:bg-surface-800/50">
                    <th>Container</th>
                    <th>Image</th>
                    <th>Status</th>
                    <th>Ports</th>
                    <th class="text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="container in dockerContainers" :key="container.ID">
                    <td>
                      <div class="flex items-center gap-3">
                        <div :class="['w-10 h-10 rounded-lg flex items-center justify-center', container.running ? 'bg-green-100 dark:bg-green-500/20' : 'bg-surface-100 dark:bg-surface-800']">
                          <span :class="['material-symbols-rounded', container.running ? 'text-green-600' : 'text-surface-400']">deployed_code</span>
                        </div>
                        <div>
                          <div class="font-medium">{{ container.Names }}</div>
                          <div class="text-xs text-surface-500 font-mono">{{ container.ID?.substring(0, 12) }}</div>
                        </div>
                      </div>
                    </td>
                    <td><span class="font-mono text-sm">{{ container.Image }}</span></td>
                    <td>
                      <span :class="[
                        'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium',
                        container.running ? 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400' : 'bg-surface-100 dark:bg-surface-700 text-surface-600'
                      ]">{{ container.State }}</span>
                      <p class="text-xs text-surface-500 mt-1">{{ container.Status }}</p>
                    </td>
                    <td><span class="text-sm font-mono">{{ container.Ports || '-' }}</span></td>
                    <td class="text-right">
                      <div class="flex items-center justify-end gap-1">
                        <button v-if="container.running" @click="restartContainer(container.ID)" class="btn-ghost btn-sm" title="Restart">
                          <span class="material-symbols-rounded">restart_alt</span>
                        </button>
                        <button v-if="container.running" @click="stopContainer(container.ID)" class="btn-ghost btn-sm text-amber-600" title="Stop">
                          <span class="material-symbols-rounded">stop_circle</span>
                        </button>
                        <button v-else @click="startContainer(container.ID)" class="btn-ghost btn-sm text-green-600" title="Start">
                          <span class="material-symbols-rounded">play_circle</span>
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </template>
      </template>
    </div>

    <!-- Service Confirm Modal -->
    <ConfirmModal
      :show="serviceConfirmModal.show"
      :title="`${serviceConfirmModal.action} Service`"
      :message="serviceConfirmModal.message"
      :confirm-text="serviceConfirmModal.action"
      :danger="serviceConfirmModal.action === 'stop'"
      :loading="!!serviceActionLoading[serviceConfirmModal.service?.name]"
      :require-confirmation="serviceConfirmModal.action === 'stop' ? 'STOP' : ''"
      @confirm="handleServiceConfirm"
      @cancel="serviceConfirmModal.show = false"
    />

    <!-- Database Create Modal -->
    <Modal :show="dbCreateModal" title="Create Database" @close="dbCreateModal = false">
      <form @submit.prevent="createDatabase" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Database Name</label>
          <input
            v-model="newDb.name"
            type="text"
            class="input"
            placeholder="my_database"
            required
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Username (optional)</label>
          <input
            v-model="newDb.user"
            type="text"
            class="input"
            placeholder="db_user"
          />
          <p class="text-xs text-surface-500 mt-1">Leave empty to skip user creation</p>
        </div>

        <div v-if="newDb.user">
          <label class="block text-sm font-medium mb-2">Password</label>
          <div class="flex gap-2">
            <input
              v-model="newDb.password"
              type="text"
              class="input font-mono"
              placeholder="Enter or generate password"
            />
            <button type="button" @click="generateDbPassword" class="btn-secondary">
              <span class="material-symbols-rounded">casino</span>
            </button>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="dbCreateModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="dbSubmitting">
            <span v-if="dbSubmitting" class="spinner"></span>
            Create
          </button>
        </div>
      </form>
    </Modal>

    <!-- Database Delete Modal -->
    <ConfirmModal
      :show="dbDeleteModal.show"
      title="Delete Database"
      :message="`Are you sure you want to delete '${dbDeleteModal.db?.name}'? This action cannot be undone. A backup will be created.`"
      confirm-text="Delete"
      :danger="true"
      :loading="dbSubmitting"
      require-confirmation="DELETE"
      @confirm="deleteDatabase"
      @cancel="dbDeleteModal = { show: false, db: null }"
    />

    <!-- SSL Issue Modal -->
    <Modal :show="sslIssueModal.show" title="Issue SSL Certificate" size="lg" @close="sslIssueModal.show = false">
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Domain</label>
          <input
            v-model="sslIssueModal.domain"
            type="text"
            class="input"
            placeholder="example.com"
          />
        </div>

        <div class="flex items-center gap-3">
          <Toggle v-model="sslIssueModal.force" />
          <span
            class="text-sm text-gray-600 dark:text-gray-400 cursor-pointer"
            @click="sslIssueModal.force = !sslIssueModal.force"
          >
            Force re-issue (use if certificate already exists)
          </span>
        </div>

        <div v-if="sslPreflightResult" class="border rounded-xl p-4 dark:border-surface-700">
          <h4 class="font-medium mb-3 flex items-center gap-2">
            <span :class="[
              'material-symbols-rounded',
              sslPreflightResult.ready ? 'text-green-500' : 'text-amber-500'
            ]">
              {{ sslPreflightResult.ready ? 'check_circle' : 'warning' }}
            </span>
            Preflight Checks
          </h4>

          <div class="space-y-2">
            <div v-for="(value, key) in sslPreflightResult.checks" :key="key" class="flex items-center gap-2 text-sm">
              <span :class="[
                'material-symbols-rounded text-lg',
                value ? 'text-green-500' : 'text-red-500'
              ]">
                {{ value ? 'check' : 'close' }}
              </span>
              <span>{{ key.replace(/_/g, ' ') }}</span>
            </div>
          </div>

          <div v-if="sslPreflightResult.checks?.acme_vhost_fixed" class="mt-4 p-3 bg-green-50 dark:bg-green-500/10 rounded-lg">
            <p class="text-sm text-green-600 dark:text-green-400">
              <span class="material-symbols-rounded text-sm align-middle">build</span>
              Vhost config was auto-fixed to allow ACME challenge access.
            </p>
          </div>

          <div v-if="sslPreflightResult.issues?.length" class="mt-4 p-3 bg-red-50 dark:bg-red-500/10 rounded-lg">
            <p class="text-sm text-red-600 dark:text-red-400 font-medium mb-2">Issues:</p>
            <ul class="text-sm text-red-600 dark:text-red-400 space-y-1">
              <li v-for="issue in sslPreflightResult.issues" :key="issue">{{ issue }}</li>
            </ul>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="sslIssueModal.show = false" class="btn-secondary">
            Cancel
          </button>
          <button 
            v-if="!sslPreflightResult"
            @click="runPreflight" 
            class="btn-secondary"
            :disabled="!sslIssueModal.domain || sslSubmitting"
          >
            <span v-if="sslSubmitting" class="spinner"></span>
            Run Preflight
          </button>
          <button 
            v-if="sslPreflightResult?.ready"
            @click="issueCertificate" 
            class="btn-primary"
            :disabled="sslSubmitting"
          >
            <span v-if="sslSubmitting" class="spinner"></span>
            Issue Certificate
          </button>
        </div>
      </div>
    </Modal>

    <!-- SSL Delete Modal -->
    <ConfirmModal
      :show="sslDeleteModal.show"
      title="Delete Certificate"
      :message="`Are you sure you want to delete the certificate for '${sslDeleteModal.cert?.domain}'? A backup will be created.`"
      confirm-text="Delete"
      :danger="true"
      :loading="sslSubmitting"
      require-confirmation="DELETE"
      @confirm="deleteCertificate"
      @cancel="sslDeleteModal = { show: false, cert: null }"
    />

    <!-- Mail Create Modal -->
    <Modal :show="mailCreateModal" title="Create Email Account" @close="mailCreateModal = false">
      <form @submit.prevent="createMailAccount" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Domain</label>
          <select v-model="newMailAccount.domain" class="input" required>
            <option v-for="domain in mailDomainList" :key="domain" :value="domain">
              {{ domain }}
            </option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Username</label>
          <div class="flex">
            <input
              v-model="newMailAccount.email"
              type="text"
              class="input rounded-r-none"
              placeholder="username"
              required
            />
            <span class="px-3 flex items-center bg-surface-100 dark:bg-surface-800 border border-l-0 border-surface-200 dark:border-surface-700 rounded-r-xl text-surface-500">
              @{{ newMailAccount.domain }}
            </span>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Password</label>
          <div class="flex gap-2">
            <div class="relative flex items-center flex-1">
              <input
                v-model="newMailAccount.password"
                :type="showMailCreatePassword ? 'text' : 'password'"
                class="input font-mono w-full pr-9"
                placeholder="Password"
                required
              />
              <button
                type="button"
                @click="showMailCreatePassword = !showMailCreatePassword"
                class="absolute right-2 p-1 rounded hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                :title="showMailCreatePassword ? 'Hide password' : 'Show password'"
              >
                <span class="material-symbols-rounded text-sm text-surface-500">
                  {{ showMailCreatePassword ? 'visibility_off' : 'visibility' }}
                </span>
              </button>
            </div>
            <button type="button" @click="generateMailPassword" class="btn-secondary">
              <span class="material-symbols-rounded">casino</span>
            </button>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="mailCreateModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="mailSubmitting">
            <span v-if="mailSubmitting" class="spinner"></span>
            Create Account
          </button>
        </div>
      </form>
    </Modal>

    <!-- Mail Reset Password Modal -->
    <Modal :show="mailResetPasswordModal.show" title="Reset Password" @close="mailResetPasswordModal = { show: false, account: null }">
      <form @submit.prevent="resetMailPassword" class="space-y-4">
        <p class="text-surface-600 dark:text-surface-400">
          Reset password for <strong>{{ mailResetPasswordModal.account?.email }}</strong>
        </p>

        <div>
          <label class="block text-sm font-medium mb-2">New Password</label>
          <div class="flex gap-2">
            <div class="relative flex items-center flex-1">
              <input
                v-model="newMailPassword"
                :type="showMailResetPassword ? 'text' : 'password'"
                class="input font-mono w-full pr-9"
                placeholder="New password"
                required
              />
              <button
                type="button"
                @click="showMailResetPassword = !showMailResetPassword"
                class="absolute right-2 p-1 rounded hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
                :title="showMailResetPassword ? 'Hide password' : 'Show password'"
              >
                <span class="material-symbols-rounded text-sm text-surface-500">
                  {{ showMailResetPassword ? 'visibility_off' : 'visibility' }}
                </span>
              </button>
            </div>
            <button type="button" @click="generateMailPassword" class="btn-secondary">
              <span class="material-symbols-rounded">casino</span>
            </button>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="mailResetPasswordModal = { show: false, account: null }" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="mailSubmitting || !newMailPassword">
            <span v-if="mailSubmitting" class="spinner"></span>
            Reset Password
          </button>
        </div>
      </form>
    </Modal>

    <!-- Mail Delete Modal -->
    <ConfirmModal
      :show="mailDeleteModal.show"
      title="Delete Email Account"
      :message="`Are you sure you want to delete '${mailDeleteModal.item?.email || mailDeleteModal.item?.source}'? This will permanently remove all emails.`"
      confirm-text="Delete"
      :danger="true"
      :loading="mailSubmitting"
      require-confirmation="DELETE"
      @confirm="deleteMailItem"
      @cancel="mailDeleteModal = { show: false, type: null, item: null }"
    />

    <!-- Mail Suspend Modal -->
    <Modal
      :show="mailSuspendModal.show"
      title="Suspend Email Login"
      @close="mailSuspendModal = { show: false, account: null, reason: '', busy: false }"
    >
      <div class="space-y-4">
        <div class="rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-500/10 dark:border-amber-500/30 px-3 py-2 text-sm text-amber-800 dark:text-amber-200 flex items-start gap-2">
          <span class="material-symbols-rounded text-base shrink-0 mt-0.5">block</span>
          <span>
            <strong>{{ mailSuspendModal.account?.email }}</strong> will be unable to log in
            anywhere &mdash; Outlook, phones, IMAP/POP3, SMTP and webmail. Any open sessions
            are disconnected immediately. <strong>Incoming mail keeps being received</strong>
            and will be waiting when you resume the account.
          </span>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Reason (optional)</label>
          <input
            v-model="mailSuspendModal.reason"
            type="text"
            class="input w-full"
            maxlength="255"
            placeholder="e.g. offboarding, suspected compromise, non-payment"
          />
        </div>

        <div class="flex justify-end gap-3 pt-2">
          <button
            type="button"
            class="btn-secondary"
            :disabled="mailSuspendModal.busy"
            @click="mailSuspendModal = { show: false, account: null, reason: '', busy: false }"
          >
            Cancel
          </button>
          <button
            type="button"
            class="btn-primary bg-amber-600 hover:bg-amber-700 border-amber-600"
            :disabled="mailSuspendModal.busy"
            @click="confirmSuspendAccount"
          >
            <span v-if="mailSuspendModal.busy" class="spinner"></span>
            <span v-else class="material-symbols-rounded text-sm">block</span>
            Suspend Login
          </button>
        </div>
      </div>
    </Modal>

    <!-- Mail Redirect Modal -->
    <Modal :show="mailRedirectModal.show" title="Manage Email Redirects" @close="closeMailRedirectModal">
      <div class="space-y-5">
        <!-- Source email info -->
        <div class="p-4 bg-blue-50 dark:bg-blue-500/10 rounded-xl">
          <div class="flex items-start gap-3">
            <span class="material-symbols-rounded text-blue-500">mail</span>
            <div>
              <p class="text-sm text-blue-700 dark:text-blue-300">
                Managing redirects for:
              </p>
              <p class="font-semibold text-blue-800 dark:text-blue-200">{{ mailRedirectModal.source }}</p>
            </div>
          </div>
        </div>

        <!-- Current Forwards List -->
        <div class="space-y-2">
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300">
            Current Forwards
            <span class="text-surface-400 font-normal">({{ mailRedirectModal.destinations.length }})</span>
          </label>
          
          <div v-if="mailRedirectModal.destinations.length" class="space-y-2">
            <div 
              v-for="dest in mailRedirectModal.destinations" 
              :key="dest"
              class="flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-800 rounded-xl group"
            >
              <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-primary-500">arrow_forward</span>
                <span class="font-medium">{{ dest }}</span>
              </div>
              <button 
                @click="removeMailForward(dest)"
                class="btn-ghost btn-sm text-red-500 opacity-0 group-hover:opacity-100 transition-opacity"
                :disabled="mailRedirectModal.loading"
                title="Remove forward"
              >
                <span class="material-symbols-rounded">close</span>
              </button>
            </div>
          </div>
          
          <div v-else class="p-4 text-center text-surface-400 bg-surface-50 dark:bg-surface-800 rounded-xl">
            <span class="material-symbols-rounded text-2xl mb-1 block">forward_to_inbox</span>
            <p class="text-sm">No forwards configured</p>
          </div>
        </div>

        <!-- Add New Forward -->
        <div class="space-y-2">
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300">Add New Forward</label>
          <div class="flex gap-2">
            <input 
              v-model="mailRedirectModal.newDestination" 
              type="email" 
              class="flex-1 px-4 py-3 rounded-xl border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800 focus:ring-2 focus:ring-primary-500 focus:border-transparent outline-none transition-all"
              placeholder="recipient@example.com"
              @keyup.enter="addMailForward"
            />
            <button 
              @click="addMailForward"
              class="btn-primary px-4"
              :disabled="mailRedirectModal.loading || !mailRedirectModal.newDestination"
            >
              <span v-if="mailRedirectModal.loading" class="spinner"></span>
              <span class="material-symbols-rounded" v-else>add</span>
            </button>
          </div>
        </div>
        
        <!-- Keep Copy Toggle -->
        <div class="flex items-center justify-between p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <div class="pr-4">
            <p class="font-medium">Keep a copy locally</p>
            <p class="text-sm text-surface-500">Also deliver to the original mailbox</p>
          </div>
          <button 
            @click="toggleMailKeepCopy"
            :disabled="mailRedirectModal.loading"
            class="relative"
          >
            <Toggle :modelValue="mailRedirectModal.keepCopy" />
          </button>
        </div>
        
        <div class="flex justify-end gap-3 pt-2">
          <button type="button" @click="closeMailRedirectModal" class="btn-secondary">
            Close
          </button>
        </div>
      </div>
    </Modal>

    <!-- Mail Migration Type Selection Popup -->
    <Modal :show="mailMigratePopup" title="Migrate Emails" @close="mailMigratePopup = false">
      <div class="space-y-4">
        <p class="text-surface-600 dark:text-surface-400">
          Import emails from an external mail server using IMAP. Choose migration type:
        </p>
        
        <div class="grid grid-cols-2 gap-4">
          <button
            @click="selectMigrationType('single')"
            class="p-6 rounded-xl border-2 border-surface-200 dark:border-surface-700 hover:border-primary-500 dark:hover:border-primary-500 transition-colors text-left group"
          >
            <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center mb-4 group-hover:bg-blue-200 dark:group-hover:bg-blue-500/30 transition-colors">
              <span class="material-symbols-rounded text-blue-600 dark:text-blue-400 text-2xl">person</span>
            </div>
            <h4 class="font-semibold mb-1">Single Account</h4>
            <p class="text-sm text-surface-500">Migrate one email account from another server</p>
          </button>
          
          <button
            @click="selectMigrationType('multiple')"
            class="p-6 rounded-xl border-2 border-surface-200 dark:border-surface-700 hover:border-primary-500 dark:hover:border-primary-500 transition-colors text-left group"
          >
            <div class="w-12 h-12 rounded-xl bg-emerald-100 dark:bg-emerald-500/20 flex items-center justify-center mb-4 group-hover:bg-emerald-200 dark:group-hover:bg-emerald-500/30 transition-colors">
              <span class="material-symbols-rounded text-emerald-600 dark:text-emerald-400 text-2xl">groups</span>
            </div>
            <h4 class="font-semibold mb-1">Multiple Accounts</h4>
            <p class="text-sm text-surface-500">Batch migrate from a list of accounts</p>
          </button>
        </div>

        <!-- Active Migrations -->
        <div v-if="runningCount > 0" class="mt-6 pt-4 border-t border-surface-200 dark:border-surface-700">
          <h4 class="font-medium text-sm text-surface-500 mb-3">ACTIVE MIGRATIONS</h4>
          <div class="space-y-2">
            <div
              v-for="m in mailMigrations.filter((x) => x.status === 'running' || x.status === 'pending')"
              :key="m.id"
              class="flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-800 rounded-xl"
            >
              <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-primary-500 animate-spin">progress_activity</span>
                <div>
                  <p class="text-sm font-medium">{{ m.current_account || 'Starting...' }}</p>
                  <p class="text-xs text-surface-500">from {{ m.source_host }}</p>
                </div>
              </div>
              <span class="text-sm font-medium text-primary-600">{{ m.progress }}%</span>
            </div>
          </div>
        </div>
      </div>
    </Modal>

    <!-- Single Migration Modal -->
    <Modal :show="mailMigrateSingleModal" title="Migrate Single Account" size="lg" @close="mailMigrateSingleModal = false">
      <form @submit.prevent="startSingleMigration" class="space-y-6">
        <div class="bg-blue-50 dark:bg-blue-500/10 rounded-xl p-4">
          <div class="flex gap-3">
            <span class="material-symbols-rounded text-blue-500">info</span>
            <p class="text-sm text-blue-700 dark:text-blue-300">
              Copies all mail from the old account into the new one with imapsync.
              It's <strong>read-only on the source</strong> — nothing is ever deleted there, and re-running only tops up new messages.
            </p>
          </div>
        </div>

        <!-- Source (old account) -->
        <div class="space-y-4 rounded-xl p-4 bg-surface-50 dark:bg-white/5 border border-surface-100 dark:border-white/10">
          <h4 class="text-xs font-semibold uppercase tracking-wide text-surface-500">Source — old account</h4>
          <div>
            <label class="block text-sm font-medium mb-2">Source email <span class="text-red-500">*</span></label>
            <input
              v-model="newMigration.email"
              type="email"
              class="input"
              placeholder="you@gmail.com"
              required
            />
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium mb-2">Old password <span class="text-red-500">*</span></label>
              <input
                v-model="newMigration.oldPassword"
                type="password"
                class="input"
                placeholder="Password on old server"
                required
              />
            </div>
            <div>
              <label class="block text-sm font-medium mb-2">Old mail server <span class="text-red-500">*</span></label>
              <input
                v-model="newMigration.sourceHost"
                type="text"
                class="input"
                placeholder="auto-detected from email"
                required
              />
              <p class="text-xs mt-1">
                <span v-if="newMigration.sourceHost && newMigration.sourceHost === autoSourceHost" class="text-emerald-600 dark:text-emerald-400">
                  <span class="material-symbols-rounded text-xs align-middle">auto_awesome</span>
                  Auto-detected — edit if your provider differs
                </span>
                <span v-else class="text-surface-500">IMAP host of the old provider</span>
              </p>
            </div>
          </div>
        </div>

        <!-- Destination (this server) -->
        <div class="space-y-4 rounded-xl p-4 bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700">
          <h4 class="text-xs font-semibold uppercase tracking-wide text-surface-500">Destination — this server</h4>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium mb-2">Destination email</label>
              <input
                v-model="newMigration.destEmail"
                type="email"
                class="input"
                :placeholder="newMigration.email || 'user@yourdomain.com'"
              />
              <p class="text-xs text-surface-500 mt-1">Leave empty to keep the same address</p>
            </div>
            <div>
              <label class="block text-sm font-medium mb-2">New password <span class="text-red-500">*</span></label>
              <input
                v-model="newMigration.newPassword"
                type="password"
                class="input"
                placeholder="Password on this server"
                required
              />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">New mail server</label>
            <input
              v-model="newMigration.destHost"
              type="text"
              class="input"
              :placeholder="serverHostname || 'this server'"
            />
            <p class="text-xs text-surface-500 mt-1">
              <span class="material-symbols-rounded text-xs align-middle">dns</span>
              Auto-filled with this server — leave as is unless you're migrating somewhere else
            </p>
          </div>
        </div>

        <!-- Sync phase + provisioning -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="text-sm font-medium mb-2 flex items-center gap-1.5">
              Sync phase
              <button
                type="button"
                @click="showSyncHelp = !showSyncHelp"
                class="text-surface-400 hover:text-primary-500 leading-none"
                title="What do these mean?"
              >
                <span class="material-symbols-rounded text-base align-middle">info</span>
              </button>
            </label>
            <select v-model="newMigration.mode" class="input">
              <option value="initial">Initial — first full copy</option>
              <option value="delta">Delta — top up new mail</option>
              <option value="final">Final — cutover sync</option>
            </select>
            <p class="text-xs text-surface-500 mt-1">
              Re-running is safe: imapsync only copies new messages and never deletes.
            </p>
            <div v-if="showSyncHelp" class="mt-2 text-xs text-surface-600 dark:text-surface-400 bg-surface-50 dark:bg-white/5 border border-surface-100 dark:border-white/10 rounded-lg p-3 space-y-1.5">
              <p><strong class="text-surface-800 dark:text-surface-200">Initial</strong> — the first full copy of everything. Run once at the start.</p>
              <p><strong class="text-surface-800 dark:text-surface-200">Delta</strong> — a quick top-up that copies only mail that arrived since the last run. Run it repeatedly while you prepare to switch over.</p>
              <p><strong class="text-surface-800 dark:text-surface-200">Final</strong> — the last sync at cut-over (after DNS/MX points here), catching the final few messages so nothing is missed.</p>
              <p class="text-surface-500">All three run the exact same non-destructive copy — the phase is just a label for your own tracking.</p>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Provisioning</label>
            <div class="flex items-start gap-3 text-sm">
              <Toggle v-model="newMigration.createMailbox" />
              <span class="cursor-pointer" @click="newMigration.createMailbox = !newMigration.createMailbox">
                Create the destination mailbox if it doesn't exist
                <span class="block text-xs text-surface-500 mt-0.5">
                  Auto-creates {{ (newMigration.destEmail || newMigration.email) || 'the new mailbox' }} on this server before copying — skipped if it already exists.
                </span>
              </span>
            </div>
          </div>
        </div>

        <!-- Preflight (test connection) results -->
        <div v-if="migratePreflight.ran" class="rounded-xl border p-3 space-y-2"
          :class="migratePreflight.allOk
            ? 'border-green-200 dark:border-green-500/30 bg-green-50 dark:bg-green-500/10'
            : 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10'">
          <div class="flex items-center gap-2 text-sm font-medium"
            :class="migratePreflight.allOk ? 'text-green-700 dark:text-green-300' : 'text-amber-700 dark:text-amber-300'">
            <span class="material-symbols-rounded text-base">{{ migratePreflight.allOk ? 'check_circle' : 'warning' }}</span>
            {{ migratePreflight.allOk ? 'Connection OK' : 'Connection check found issues' }}
          </div>
          <div v-for="r in migratePreflight.results" :key="r.email" class="text-xs text-surface-600 dark:text-surface-300 space-y-0.5">
            <div class="font-medium text-surface-700 dark:text-surface-200">{{ r.email }}</div>
            <div class="flex flex-wrap gap-x-4 gap-y-0.5">
              <span class="inline-flex items-center gap-1">
                <span class="material-symbols-rounded text-sm" :class="r.source_ok ? 'text-green-500' : 'text-red-500'">{{ r.source_ok ? 'check' : 'close' }}</span>
                Source login
              </span>
              <span class="inline-flex items-center gap-1">
                <span class="material-symbols-rounded text-sm" :class="r.dest_ok ? 'text-green-500' : 'text-red-500'">{{ r.dest_ok ? 'check' : 'close' }}</span>
                Destination login
              </span>
            </div>
            <p v-if="!r.dest_ok && newMigration.createMailbox" class="text-surface-500">
              Destination mailbox may not exist yet — it will be auto-created when you start.
            </p>
            <p v-if="r.error" class="text-red-500 break-words">{{ r.error }}</p>
          </div>
        </div>

        <div class="flex justify-between items-center gap-3 pt-4">
          <button type="button" @click="runPreflightSingle" class="btn-secondary" :disabled="migratePreflight.running">
            <span v-if="migratePreflight.running" class="spinner"></span>
            <span class="material-symbols-rounded" v-else>wifi_tethering</span>
            Test connection
          </button>
          <div class="flex gap-3">
            <button type="button" @click="mailMigrateSingleModal = false" class="btn-secondary">
              Cancel
            </button>
            <button type="submit" class="btn-primary" :disabled="mailMigrateSubmitting">
              <span v-if="mailMigrateSubmitting" class="spinner"></span>
              <span class="material-symbols-rounded" v-else>move_to_inbox</span>
              Start Migration
            </button>
          </div>
        </div>
      </form>
    </Modal>

    <!-- Multiple Migration Modal -->
    <Modal :show="mailMigrateMultipleModal" title="Migrate Multiple Accounts" size="xl" @close="mailMigrateMultipleModal = false">
      <form @submit.prevent="startMultipleMigration" class="space-y-6">
        <div class="bg-emerald-50 dark:bg-emerald-500/10 rounded-xl p-4">
          <div class="flex gap-3">
            <span class="material-symbols-rounded text-emerald-500">info</span>
            <p class="text-sm text-emerald-700 dark:text-emerald-300">
              Batch migrate multiple accounts, one per line. Same address both sides:
              <code class="bg-emerald-100 dark:bg-emerald-500/20 px-1 rounded">email;old_password;new_password</code> —
              or a different destination:
              <code class="bg-emerald-100 dark:bg-emerald-500/20 px-1 rounded">source_email;old_password;dest_email;new_password</code>.
            </p>
          </div>
        </div>

        <!-- Servers -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Old Server <span class="text-red-500">*</span></label>
            <input
              v-model="newMigration.sourceHost"
              type="text"
              class="input"
              placeholder="old.mailserver.com"
              required
            />
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">New Server</label>
            <input
              v-model="newMigration.destHost"
              type="text"
              class="input"
              :placeholder="serverHostname || 'new.mailserver.com'"
            />
            <p class="text-xs text-surface-500 mt-1">Leave empty for: {{ serverHostname || 'this server' }}</p>
          </div>
        </div>

        <!-- Input Method Tabs -->
        <div>
          <div class="flex border-b border-surface-200 dark:border-surface-700 mb-4">
            <button
              type="button"
              @click="mailMigrateMultipleTab = 'paste'"
              :class="[
                'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
                mailMigrateMultipleTab === 'paste'
                  ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                  : 'border-transparent text-surface-500 hover:text-surface-700'
              ]"
            >
              <span class="material-symbols-rounded text-lg align-middle mr-1">content_paste</span>
              Paste Text
            </button>
            <button
              type="button"
              @click="mailMigrateMultipleTab = 'upload'"
              :class="[
                'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
                mailMigrateMultipleTab === 'upload'
                  ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                  : 'border-transparent text-surface-500 hover:text-surface-700'
              ]"
            >
              <span class="material-symbols-rounded text-lg align-middle mr-1">upload_file</span>
              Upload File
            </button>
          </div>

          <div v-if="mailMigrateMultipleTab === 'paste'">
            <label class="block text-sm font-medium mb-2">Account List <span class="text-red-500">*</span></label>
            <textarea
              v-model="newMigration.accountsText"
              class="input font-mono text-sm"
              rows="8"
              placeholder="user1@domain.com;oldpass1;newpass1&#10;user2@domain.com;oldpass2;newpass2&#10;user3@domain.com;oldpass3;newpass3"
            ></textarea>
            <div class="flex items-center justify-between mt-1">
              <p class="text-xs text-surface-500">One per line: email;old_password;new_password — or source_email;old_password;dest_email;new_password</p>
              <button type="button" @click="downloadMigrationExample" class="text-xs text-primary-500 hover:text-primary-600 flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">download</span>
                Download example
              </button>
            </div>
          </div>

          <div v-else>
            <label class="block text-sm font-medium mb-2">Upload CSV or TXT File</label>
            <div class="border-2 border-dashed border-surface-300 dark:border-surface-600 rounded-xl p-8 text-center">
              <input
                type="file"
                accept=".csv,.txt"
                @change="handleFileUpload"
                class="hidden"
                id="migration-file-upload"
              />
              <label for="migration-file-upload" class="cursor-pointer">
                <span class="material-symbols-rounded text-4xl text-surface-400 mb-2 block">upload_file</span>
                <p class="text-sm text-surface-600 dark:text-surface-400">
                  Click to upload or drag and drop
                </p>
                <p class="text-xs text-surface-500 mt-1">CSV or TXT file (email;old_pass;new_pass)</p>
              </label>
            </div>
            <button type="button" @click="downloadMigrationExample" class="mt-3 text-xs text-primary-500 hover:text-primary-600 flex items-center gap-1 mx-auto">
              <span class="material-symbols-rounded text-sm">download</span>
              Download example file
            </button>
            <div v-if="newMigration.accountsText" class="mt-4">
              <p class="text-sm text-surface-600 dark:text-surface-400 mb-2">
                <span class="material-symbols-rounded text-green-500 align-middle">check_circle</span>
                File loaded: {{ parseAccountsText(newMigration.accountsText).length }} accounts found
              </p>
            </div>
          </div>
        </div>

        <!-- Sync phase + provisioning -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="text-sm font-medium mb-2 flex items-center gap-1.5">
              Sync phase
              <button
                type="button"
                @click="showSyncHelp = !showSyncHelp"
                class="text-surface-400 hover:text-primary-500 leading-none"
                title="What do these mean?"
              >
                <span class="material-symbols-rounded text-base align-middle">info</span>
              </button>
            </label>
            <select v-model="newMigration.mode" class="input">
              <option value="initial">Initial — first full copy</option>
              <option value="delta">Delta — top up new mail</option>
              <option value="final">Final — cutover sync</option>
            </select>
            <p class="text-xs text-surface-500 mt-1">
              Re-running is safe: imapsync only copies new messages and never deletes.
            </p>
            <div v-if="showSyncHelp" class="mt-2 text-xs text-surface-600 dark:text-surface-400 bg-surface-50 dark:bg-white/5 border border-surface-100 dark:border-white/10 rounded-lg p-3 space-y-1.5">
              <p><strong class="text-surface-800 dark:text-surface-200">Initial</strong> — the first full copy of everything. Run once at the start.</p>
              <p><strong class="text-surface-800 dark:text-surface-200">Delta</strong> — a quick top-up that copies only mail that arrived since the last run. Run it repeatedly while you prepare to switch over.</p>
              <p><strong class="text-surface-800 dark:text-surface-200">Final</strong> — the last sync at cut-over (after DNS/MX points here), catching the final few messages so nothing is missed.</p>
              <p class="text-surface-500">All three run the exact same non-destructive copy — the phase is just a label for your own tracking.</p>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Provisioning</label>
            <div class="flex items-center gap-3 text-sm">
              <Toggle v-model="newMigration.createMailbox" />
              <span class="cursor-pointer" @click="newMigration.createMailbox = !newMigration.createMailbox">
                Create destination mailboxes first if they don't exist
              </span>
            </div>
          </div>
        </div>

        <!-- Preflight (test connection) results -->
        <div v-if="migratePreflight.ran" class="rounded-xl border p-3 space-y-2"
          :class="migratePreflight.allOk
            ? 'border-green-200 dark:border-green-500/30 bg-green-50 dark:bg-green-500/10'
            : 'border-amber-200 dark:border-amber-500/30 bg-amber-50 dark:bg-amber-500/10'">
          <div class="flex items-center gap-2 text-sm font-medium"
            :class="migratePreflight.allOk ? 'text-green-700 dark:text-green-300' : 'text-amber-700 dark:text-amber-300'">
            <span class="material-symbols-rounded text-base">{{ migratePreflight.allOk ? 'check_circle' : 'warning' }}</span>
            {{ migratePreflight.allOk ? 'All connections OK' : 'Connection check found issues' }}
          </div>
          <div class="max-h-48 overflow-y-auto space-y-2">
            <div v-for="r in migratePreflight.results" :key="r.email" class="text-xs text-surface-600 dark:text-surface-300 space-y-0.5">
              <div class="font-medium text-surface-700 dark:text-surface-200">{{ r.email }}</div>
              <div class="flex flex-wrap gap-x-4 gap-y-0.5">
                <span class="inline-flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm" :class="r.source_ok ? 'text-green-500' : 'text-red-500'">{{ r.source_ok ? 'check' : 'close' }}</span>
                  Source login
                </span>
                <span class="inline-flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm" :class="r.dest_ok ? 'text-green-500' : 'text-red-500'">{{ r.dest_ok ? 'check' : 'close' }}</span>
                  Destination login
                </span>
              </div>
              <p v-if="r.error" class="text-red-500 break-words">{{ r.error }}</p>
            </div>
          </div>
          <p v-if="!migratePreflight.allOk && newMigration.createMailbox" class="text-xs text-surface-500">
            Destination mailboxes that don't exist yet will be auto-created when you start.
          </p>
        </div>

        <div class="flex justify-between items-center gap-3 pt-4">
          <button type="button" @click="runPreflightBatch" class="btn-secondary" :disabled="migratePreflight.running || !newMigration.accountsText">
            <span v-if="migratePreflight.running" class="spinner"></span>
            <span class="material-symbols-rounded" v-else>wifi_tethering</span>
            Test connections
          </button>
          <div class="flex gap-3">
            <button type="button" @click="mailMigrateMultipleModal = false" class="btn-secondary">
              Cancel
            </button>
            <button type="submit" class="btn-primary" :disabled="mailMigrateSubmitting || !newMigration.accountsText">
              <span v-if="mailMigrateSubmitting" class="spinner"></span>
              <span class="material-symbols-rounded" v-else>move_to_inbox</span>
              Start Migration ({{ parseAccountsText(newMigration.accountsText).length }} accounts)
            </button>
          </div>
        </div>
      </form>
    </Modal>

    <!-- Migration Progress Detail Modal -->
    <Modal :show="migrationDetailModal" title="Migration Progress" size="2xl" @close="closeMigrationDetail">
      <div v-if="migrationDetail" class="space-y-6">
        <!-- Status Header -->
        <div class="flex items-center justify-between p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <div class="flex items-center gap-4">
            <div :class="[
              'w-12 h-12 rounded-xl flex items-center justify-center',
              migrationDetail.status === 'running' ? 'bg-blue-100 dark:bg-blue-500/20' :
              migrationDetail.status === 'completed' ? 'bg-green-100 dark:bg-green-500/20' :
              migrationDetail.status === 'failed' ? 'bg-red-100 dark:bg-red-500/20' :
              'bg-surface-200 dark:bg-surface-700'
            ]">
              <span :class="[
                'material-symbols-rounded text-2xl',
                migrationDetail.status === 'running' ? 'text-blue-500 animate-spin' :
                migrationDetail.status === 'completed' ? 'text-green-500' :
                migrationDetail.status === 'failed' ? 'text-red-500' :
                'text-surface-500'
              ]">
                {{ migrationDetail.status === 'running' ? 'progress_activity' : 
                   migrationDetail.status === 'completed' ? 'check_circle' :
                   migrationDetail.status === 'failed' ? 'error' :
                   migrationDetail.status === 'cancelled' ? 'cancel' : 'schedule' }}
              </span>
            </div>
            <div>
              <h4 class="font-semibold">{{ migrationDetail.source_host }}</h4>
              <p class="text-sm text-surface-500">
                {{ migrationDetail.status === 'completed' ? 'Migration complete'
                   : migrationDetail.status === 'failed' ? 'Migration failed'
                   : migrationDetail.status === 'cancelled' ? 'Migration cancelled'
                   : migrationDetail.current_account || 'Waiting to start…' }}
              </p>
            </div>
          </div>
          <div class="text-right">
            <span class="text-2xl font-bold text-primary-600">{{ migrationDetail.progress }}%</span>
            <p class="text-xs text-surface-500 uppercase">Progress</p>
          </div>
        </div>

        <!-- Progress Bar -->
        <div>
          <div class="flex justify-between text-sm mb-2">
            <span class="text-surface-500">Overall Progress</span>
            <span class="font-medium">{{ migrationDetail.progress }}%</span>
          </div>
          <div class="w-full bg-surface-200 dark:bg-surface-700 rounded-full h-3">
            <div 
              class="bg-gradient-to-r from-primary-500 to-emerald-500 h-3 rounded-full transition-all duration-500"
              :style="{ width: `${migrationDetail.progress}%` }"
            ></div>
          </div>
          <!-- Absolute counts (messages / bytes / accounts / verified) -->
          <div class="flex flex-wrap items-center justify-between gap-2 mt-2 text-xs text-surface-500">
            <span>
              {{ formatNumber(migrationDetail.transferred_messages) }} / {{ formatNumber(migrationDetail.total_messages) }} emails copied
              · {{ formatBytes(migrationDetail.transferred_bytes) }}
            </span>
            <span class="flex items-center gap-2">
              <span>{{ migrationDetail.completed_accounts ?? 0 }} / {{ migrationDetail.total_accounts ?? 0 }} accounts</span>
              <span
                v-if="migrationDetail.status === 'completed'"
                class="inline-flex items-center gap-1"
                :class="migrationDetail.verified ? 'text-green-500' : 'text-amber-500'"
              >
                <span class="material-symbols-rounded text-sm">{{ migrationDetail.verified ? 'verified' : 'warning' }}</span>
                {{ migrationDetail.verified ? 'Verified' : 'Unverified' }}
              </span>
            </span>
          </div>
        </div>

        <!-- Per-Account Progress (for batch) -->
        <div v-if="migrationDetail.accounts && migrationDetail.accounts.length > 1" class="space-y-2">
          <h5 class="font-medium text-sm text-surface-500 uppercase tracking-wide">Accounts</h5>
          <div class="max-h-40 overflow-y-auto space-y-1 pr-2">
            <div 
              v-for="acc in migrationDetail.accounts" 
              :key="acc.email"
              class="flex items-center justify-between py-2 px-3 rounded-lg"
              :class="acc.status === 'running' ? 'bg-blue-50 dark:bg-blue-500/10' : 'bg-surface-50 dark:bg-surface-800'"
            >
              <div class="flex items-center gap-2">
                <span :class="[
                  'material-symbols-rounded text-lg',
                  acc.status === 'completed' ? 'text-green-500' :
                  acc.status === 'running' ? 'text-blue-500 animate-spin' :
                  acc.status === 'failed' ? 'text-red-500' : 'text-surface-400'
                ]">
                  {{ acc.status === 'completed' ? 'check_circle' :
                     acc.status === 'running' ? 'progress_activity' :
                     acc.status === 'failed' ? 'error' : 'schedule' }}
                </span>
                <span class="text-sm font-mono">{{ acc.email }}</span>
              </div>
              <span :class="[
                'text-xs font-medium px-2 py-0.5 rounded-full',
                acc.status === 'completed' ? 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400' :
                acc.status === 'running' ? 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400' :
                acc.status === 'failed' ? 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400' :
                'bg-surface-200 text-surface-600 dark:bg-surface-700 dark:text-surface-400'
              ]">
                {{ acc.status }}
              </span>
            </div>
          </div>
        </div>

        <!-- Live Terminal Output -->
        <div>
          <div class="flex items-center justify-between mb-2">
            <h5 class="font-medium text-sm text-surface-500 uppercase tracking-wide flex items-center gap-2">
              <span class="material-symbols-rounded text-lg">terminal</span>
              Live Output
            </h5>
            <div class="flex items-center gap-2">
              <span v-if="migrationDetail.status === 'running'" class="flex items-center gap-1 text-xs text-green-500">
                <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                Live
              </span>
              <button @click="fetchMigrationLogs(migrationDetail.id, true)" class="btn-ghost btn-sm">
                <span class="material-symbols-rounded" :class="migrationLogsLoading && 'animate-spin'">refresh</span>
              </button>
            </div>
          </div>
          <div class="bg-surface-900 dark:bg-black rounded-xl p-4 font-mono text-xs text-green-400 max-h-80 overflow-y-auto whitespace-pre-wrap">
            <template v-if="migrationLogsLoading && !migrationLogs">
              <div class="text-surface-500">Loading logs...</div>
            </template>
            <template v-else-if="migrationLogs">
              {{ migrationLogs }}
            </template>
            <template v-else>
              <div class="text-surface-500">No logs available yet...</div>
            </template>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex justify-between pt-4 border-t border-surface-200 dark:border-surface-700">
          <button 
            v-if="migrationDetail.status === 'running' || migrationDetail.status === 'pending'"
            @click="cancelMigration(migrationDetail.id); closeMigrationDetail()"
            class="btn-secondary text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
          >
            <span class="material-symbols-rounded">cancel</span>
            Cancel Migration
          </button>
          <button 
            v-else-if="['completed', 'failed', 'cancelled'].includes(migrationDetail.status)"
            @click="deleteMigration(migrationDetail.id); closeMigrationDetail()"
            class="btn-secondary text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
          >
            <span class="material-symbols-rounded">delete</span>
            Delete from list
          </button>
          <div v-else></div>
          <button @click="closeMigrationDetail" class="btn-primary">
            Close
          </button>
        </div>
      </div>
    </Modal>

    <!-- DNS Add Record Modal -->
    <Modal :show="dnsCreateRecordModal" title="Add DNS Record" @close="dnsCreateRecordModal = false">
      <form @submit.prevent="addDnsRecord" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Name</label>
          <input
            v-model="newDnsRecord.name"
            type="text"
            class="input font-mono"
            :placeholder="dnsSelectedZone?.name"
            required
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Type</label>
          <select v-model="newDnsRecord.type" class="input">
            <option v-for="type in dnsRecordTypes" :key="type" :value="type">{{ type }}</option>
          </select>
        </div>

        <div v-if="newDnsRecord.type === 'MX' || newDnsRecord.type === 'SRV'">
          <label class="block text-sm font-medium mb-2">Priority</label>
          <input
            v-model.number="newDnsRecord.prio"
            type="number"
            class="input"
            min="0"
            max="65535"
            placeholder="10"
          />
          <p class="text-xs text-surface-500 mt-1">Lower values = higher priority</p>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Content</label>
          <input
            v-model="newDnsRecord.content"
            type="text"
            class="input font-mono"
            placeholder="1.2.3.4"
            required
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">TTL</label>
          <input
            v-model.number="newDnsRecord.ttl"
            type="number"
            class="input"
            min="60"
          />
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="dnsCreateRecordModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="dnsSubmitting">
            <span v-if="dnsSubmitting" class="spinner"></span>
            Add Record
          </button>
        </div>
      </form>
    </Modal>

    <!-- DNS Edit Record Modal -->
    <Modal :show="dnsEditModal.show" title="Edit DNS Record" @close="dnsEditModal = { show: false, record: null }">
      <form @submit.prevent="updateDnsRecord" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Name</label>
          <input
            v-model="editingDnsRecord.name"
            type="text"
            class="input font-mono"
            :placeholder="dnsSelectedZone?.name"
            required
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Type</label>
          <select v-model="editingDnsRecord.type" class="input">
            <option v-for="type in dnsRecordTypes" :key="type" :value="type">{{ type }}</option>
          </select>
        </div>

        <div v-if="editingDnsRecord.type === 'MX' || editingDnsRecord.type === 'SRV'">
          <label class="block text-sm font-medium mb-2">Priority</label>
          <input
            v-model.number="editingDnsRecord.prio"
            type="number"
            class="input"
            min="0"
            max="65535"
            placeholder="10"
          />
          <p class="text-xs text-surface-500 mt-1">Lower values = higher priority</p>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Content</label>
          <input
            v-model="editingDnsRecord.content"
            type="text"
            class="input font-mono"
            placeholder="1.2.3.4"
            required
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">TTL</label>
          <input
            v-model.number="editingDnsRecord.ttl"
            type="number"
            class="input"
            min="60"
          />
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="dnsEditModal = { show: false, record: null }" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="dnsSubmitting">
            <span v-if="dnsSubmitting" class="spinner"></span>
            Save
          </button>
        </div>
      </form>
    </Modal>

    <!-- DNS Delete Record Modal -->
    <ConfirmModal
      :show="dnsDeleteModal.show"
      title="Delete DNS Record"
      :message="`Are you sure you want to delete the ${dnsDeleteModal.record?.type} record for '${dnsDeleteModal.record?.name}'?`"
      confirm-text="Delete"
      :danger="true"
      :loading="dnsSubmitting"
      require-confirmation="DELETE"
      @confirm="deleteDnsRecord"
      @cancel="dnsDeleteModal = { show: false, record: null }"
    />

    <!-- WordPress Install Modal -->
    <Modal :show="wpShowInstallModal" :title="`Install ${wpSelectedTemplate?.name || 'Application'}`" @close="wpShowInstallModal = false" size="lg">
      <form @submit.prevent="installWpApp" class="space-y-6">
        <div v-if="wpSelectedTemplate" class="flex items-center gap-4 p-4 bg-surface-50 dark:bg-surface-800/50 rounded-xl">
          <div class="w-14 h-14 rounded-xl bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-3xl text-primary-600 dark:text-primary-400">
              {{ getWpAppIcon(wpSelectedTemplate) }}
            </span>
          </div>
          <div>
            <h3 class="font-semibold text-lg">{{ wpSelectedTemplate.name }}</h3>
            <p class="text-sm text-surface-500">{{ wpSelectedTemplate.description }}</p>
          </div>
        </div>
        
        <div>
          <label class="block text-sm font-medium mb-2">Target Site *</label>
          <select v-model="wpInstallForm.domain" @change="onWpDomainChange" class="input" required>
            <option value="">Select a site...</option>
            <option v-for="site in wpSites" :key="site.domain" :value="site.domain">{{ site.domain }}</option>
          </select>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Site Title</label>
            <input v-model="wpInstallForm.site_title" type="text" class="input" :placeholder="wpInstallForm.domain || 'My Website'" />
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Database</label>
            <!-- Loading state -->
            <div v-if="wpSiteDbLoading" class="input flex items-center gap-2 text-surface-500">
              <span class="spinner-sm"></span>
              Loading databases...
            </div>
            <!-- Databases found - show dropdown -->
            <div v-else-if="wpSiteDatabases.length > 0" class="space-y-2">
              <select v-model="wpInstallForm.db_name" class="input font-mono">
                <optgroup label="Existing Databases">
                  <option v-for="db in wpSiteDatabases" :key="db.name" :value="db.name">
                    {{ db.name }} ({{ db.size_human || 'empty' }})
                  </option>
                </optgroup>
                <optgroup label="Create New">
                  <option :value="generateWpDbName(wpInstallForm.domain)">
                    {{ generateWpDbName(wpInstallForm.domain) }} (new)
                  </option>
                </optgroup>
              </select>
              <p class="text-xs text-surface-500">
                <span class="material-symbols-rounded text-xs align-middle">info</span>
                Select existing database or create new
              </p>
            </div>
            <!-- No databases - show text input -->
            <div v-else>
              <input v-model="wpInstallForm.db_name" type="text" class="input font-mono" :placeholder="generateWpDbName(wpInstallForm.domain) || 'wordpress_db'" />
              <p v-if="wpInstallForm.domain" class="text-xs text-surface-500 mt-1">
                <span class="material-symbols-rounded text-xs align-middle">add_circle</span>
                New database will be created
              </p>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Admin Email *</label>
            <input v-model="wpInstallForm.admin_email" type="email" class="input" placeholder="admin@example.com" required />
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Admin Username</label>
            <input v-model="wpInstallForm.admin_user" type="text" class="input" placeholder="admin" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Admin Password</label>
          <div class="flex gap-2">
            <input v-model="wpInstallForm.admin_password" type="text" class="input flex-1 font-mono text-sm" readonly />
            <button type="button" @click="wpInstallForm.admin_password = generateWpPassword()" class="btn-secondary" title="Regenerate">
              <span class="material-symbols-rounded">refresh</span>
            </button>
            <button type="button" @click="copyToClipboard(wpInstallForm.admin_password)" class="btn-secondary" title="Copy">
              <span class="material-symbols-rounded">content_copy</span>
            </button>
          </div>
        </div>

        <div class="flex items-center gap-2 p-3 bg-amber-50 dark:bg-amber-500/10 rounded-lg text-amber-700 dark:text-amber-400 text-sm">
          <span class="material-symbols-rounded">warning</span>
          <span>Save the admin password - it will only be shown once.</span>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-surface-200 dark:border-surface-700">
          <button type="button" @click="wpShowInstallModal = false" class="btn-secondary">Cancel</button>
          <button type="submit" class="btn-primary" :disabled="wpInstalling">
            <span v-if="wpInstalling" class="spinner-sm mr-2"></span>
            {{ wpInstalling ? 'Installing...' : 'Install Application' }}
          </button>
        </div>
      </form>
    </Modal>

    <!-- WordPress Update Modal -->
    <Modal :show="wpShowUpdateModal" @close="closeWpUpdateModal" size="lg">
      <template #title>
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-rounded text-xl text-amber-600 dark:text-amber-400">system_update_alt</span>
          </div>
          <div>
            <span class="text-lg font-semibold">Update WordPress</span>
            <p class="text-sm text-surface-500 font-normal mt-0.5">{{ wpUpdateDomain }}</p>
          </div>
        </div>
      </template>
        
      <!-- Loading state -->
      <div v-if="wpUpdatePluginsLoading" class="py-12">
        <div class="flex flex-col items-center justify-center gap-4">
          <div class="w-12 h-12 rounded-2xl bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-2xl text-amber-500 animate-pulse">sync</span>
          </div>
          <div class="text-center">
            <p class="font-medium text-surface-700 dark:text-surface-300">Checking for updates</p>
            <p class="text-sm text-surface-500 mt-1">This may take a moment...</p>
          </div>
        </div>
      </div>
      
      <template v-if="!wpUpdatePluginsLoading">
        <!-- Core Update -->
        <div v-if="wpSummaries[wpUpdateDomain]?.core_updates?.length" class="mb-4">
          <div 
            class="flex items-center justify-between p-4 rounded-xl bg-surface-50 dark:bg-surface-800 cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            @click="wpUpdateSelection.core = !wpUpdateSelection.core"
          >
            <div class="flex items-center gap-3">
              <span class="text-xs px-2 py-1 rounded-full bg-red-100 dark:bg-red-500/20 text-red-600">core</span>
              <div>
                <p class="font-medium">WordPress Core</p>
                <p class="text-xs text-surface-500">
                  {{ wpSummaries[wpUpdateDomain]?.version }} 
                  <span class="material-symbols-rounded text-xs align-middle text-amber-500">arrow_forward</span>
                  {{ wpSummaries[wpUpdateDomain]?.core_updates?.[0]?.version }}
                </p>
              </div>
            </div>
            <button 
              type="button"
              :class="[
                'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none',
                wpUpdateSelection.core ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
              ]"
              @click.stop="wpUpdateSelection.core = !wpUpdateSelection.core"
            >
              <span :class="[
                'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                wpUpdateSelection.core ? 'translate-x-5' : 'translate-x-0'
              ]"></span>
            </button>
          </div>
        </div>
        
        <!-- Plugins Section -->
        <div v-if="wpUpdatePlugins.length > 0" class="mb-4">
          <div class="flex items-center justify-between mb-3 px-1">
            <p class="text-sm font-medium text-surface-600 dark:text-surface-400">Plugins ({{ wpUpdatePlugins.length }})</p>
            <button 
              type="button"
              class="text-xs text-primary-600 dark:text-primary-400 hover:underline"
              @click="wpUpdateSelection.allPlugins = !wpUpdateSelection.allPlugins; toggleAllPlugins()"
            >
              {{ wpUpdateSelection.allPlugins ? 'Deselect all' : 'Select all' }}
            </button>
          </div>
          <div class="max-h-64 overflow-y-auto rounded-xl bg-surface-50 dark:bg-surface-800 divide-y divide-surface-200 dark:divide-surface-700">
            <div 
              v-for="plugin in wpUpdatePlugins" 
              :key="plugin.name"
              class="flex items-center justify-between px-4 py-3 cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-700/50 transition-colors"
              @click="togglePluginSelection(plugin.name)"
            >
              <div class="flex-1 min-w-0 pr-3">
                <p class="font-medium text-sm truncate">{{ plugin.title || plugin.name }}</p>
                <p class="text-xs text-surface-500">
                  {{ plugin.version }} 
                  <span class="material-symbols-rounded text-xs align-middle text-amber-500">arrow_forward</span>
                  {{ plugin.update_version || plugin.new_version || 'newer' }}
                </p>
              </div>
              <button 
                type="button"
                :class="[
                  'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none',
                  wpUpdateSelection.selectedPlugins.includes(plugin.name) ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                ]"
                @click.stop="togglePluginSelection(plugin.name)"
              >
                <span :class="[
                  'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                  wpUpdateSelection.selectedPlugins.includes(plugin.name) ? 'translate-x-5' : 'translate-x-0'
                ]"></span>
              </button>
            </div>
          </div>
        </div>
        
        <!-- No core/plugin updates message -->
        <div v-if="!wpSummaries[wpUpdateDomain]?.core_updates?.length && wpUpdatePlugins.length === 0" class="mb-4 py-4 text-center text-surface-400 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <span class="material-symbols-rounded text-2xl mb-1 block opacity-50">check_circle</span>
          <p class="text-sm">WordPress core and plugins are up to date</p>
        </div>
        
        <!-- Themes -->
        <div class="mb-2">
          <div 
            class="flex items-center justify-between p-4 rounded-xl bg-surface-50 dark:bg-surface-800 cursor-pointer hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
            @click="wpUpdateSelection.themes = !wpUpdateSelection.themes"
          >
            <div class="flex items-center gap-3">
              <span class="text-xs px-2 py-1 rounded-full bg-purple-100 dark:bg-purple-500/20 text-purple-600">themes</span>
              <div>
                <p class="font-medium">All Themes</p>
                <p class="text-xs text-surface-500">Update all installed themes</p>
              </div>
            </div>
            <button 
              type="button"
              :class="[
                'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none',
                wpUpdateSelection.themes ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
              ]"
              @click.stop="wpUpdateSelection.themes = !wpUpdateSelection.themes"
            >
              <span :class="[
                'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                wpUpdateSelection.themes ? 'translate-x-5' : 'translate-x-0'
              ]"></span>
            </button>
          </div>
        </div>
      </template>

      <template #footer>
        <button @click="closeWpUpdateModal" class="btn-ghost">
          Cancel
        </button>
        <button 
          @click="performWpUpdate" 
          class="btn-primary"
          :disabled="!wpUpdateSelection.core && wpUpdateSelection.selectedPlugins.length === 0 && !wpUpdateSelection.themes"
        >
          <span class="material-symbols-rounded">system_update_alt</span>
          Update Selected
        </button>
      </template>
    </Modal>

    <!-- WordPress Uninstall Modal -->
    <ConfirmModal
      :show="wpShowUninstallModal"
      title="Uninstall Application"
      :message="`Are you sure you want to uninstall ${wpSelectedApp?.app_name || wpSelectedApp?.app_slug} from ${wpSelectedApp?.domain}? This will remove all files and the associated database.`"
      confirm-text="Uninstall"
      :danger="true"
      require-confirmation="UNINSTALL"
      @confirm="confirmWpUninstall"
      @cancel="wpShowUninstallModal = false"
    />
  </div>
</template>

