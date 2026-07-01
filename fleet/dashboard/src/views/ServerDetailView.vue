<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'
import { useToastStore } from '../stores/toast'
import DeploymentModal from '../components/DeploymentModal.vue'
import UpdatesPanel from '../components/UpdatesPanel.vue'
import { formatLoad } from '../utils/format'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

const loading = ref(true)
const server = ref(null)
const showDeployModal = ref(false)
const showDeployMenu = ref(false)
const deployType = ref(null)
const resumeDeployId = ref(null)
const showPassword = ref(false)
const testingConnection = ref(false)
const connectionStatus = ref(null) // null, 'success', 'failed'
// Last failure message - kept on screen (toasts vanish before you can read them).
const connectionError = ref('')

// Credentials
const credentials = ref([])
const loadingCredentials = ref(false)
const visibleSecrets = ref({}) // { credentialKey: true/false }
const credentialSearch = ref('')
const showAllSecrets = ref(false)
const copiedKey = ref(null)
let copyTimer = null

// Deployment logs
const deployments = ref([])
const loadingDeployments = ref(false)
const showLogModal = ref(false)
const selectedDeployment = ref(null)
const deploymentLog = ref('')
const loadingLog = ref(false)

const fetchServer = async () => {
  try {
    const response = await api.get(`/api/servers/${route.params.id}`)
    server.value = response.data
    authorizedKeyInput.value = server.value?.ssh_authorized_key || ''
    // Also fetch deployments, credentials, audit, live DNS and CPGuard state
    fetchDeployments()
    fetchCredentials()
    fetchAudit()
    fetchDns()
    fetchCpguard()
    // Docker-provisioned boxes carry an image tag - pull live container health.
    if (server.value?.deployed_image_tag) fetchDockerStatus()
  } catch (error) {
    toast.error('Failed to load server details')
    router.push('/servers')
  } finally {
    loading.value = false
  }
}

const fetchDeployments = async () => {
  loadingDeployments.value = true
  try {
    const response = await api.get(`/api/deployments?server_id=${route.params.id}&per_page=10`)
    deployments.value = response.data?.deployments || []
  } catch (error) {
    console.error('Failed to load deployments', error)
  } finally {
    loadingDeployments.value = false
  }
}

const fetchCredentials = async () => {
  loadingCredentials.value = true
  try {
    const response = await api.get(`/api/servers/${route.params.id}/credentials`)
    credentials.value = response.data?.credentials || []
  } catch (error) {
    console.error('Failed to load credentials', error)
  } finally {
    loadingCredentials.value = false
  }
}

const toggleSecret = (key) => {
  visibleSecrets.value[key] = !visibleSecrets.value[key]
}

const isRevealed = (cred) => !cred.is_secret || showAllSecrets.value || visibleSecrets.value[cred.key]

const toggleAllSecrets = () => {
  showAllSecrets.value = !showAllSecrets.value
}

const maskValue = (value) => {
  if (!value) return '-'
  if (value.length <= 4) return '••••'
  return '••••••••••' + value.slice(-3)
}

const copyCredential = (value, label, key = null) => {
  navigator.clipboard.writeText(value).then(() => {
    toast.success(`${label} copied to clipboard`)
    if (key) {
      copiedKey.value = key
      clearTimeout(copyTimer)
      copyTimer = setTimeout(() => { copiedKey.value = null }, 1300)
    }
  }).catch(() => {
    toast.error('Failed to copy')
  })
}

// Per-category presentation. Full class strings (not interpolated) so Tailwind keeps them.
const categoryMeta = {
  panel:    { label: 'Panel Admin',  icon: 'admin_panel_settings', chip: 'bg-indigo-500/10 text-indigo-400',  desc: 'Login to the hosting control panel' },
  ssh:      { label: 'SSH Access',   icon: 'terminal',             chip: 'bg-blue-500/10 text-blue-400',      desc: 'Shell access to the server' },
  database: { label: 'Databases',    icon: 'database',             chip: 'bg-emerald-500/10 text-emerald-400', desc: 'MariaDB users & passwords' },
  mail:     { label: 'Email Login',  icon: 'mail',                 chip: 'bg-sky-500/10 text-sky-400',        desc: 'Mailbox for the webmail app' },
  services: { label: 'Services',     icon: 'lan',                  chip: 'bg-violet-500/10 text-violet-400',  desc: 'Redis, Meilisearch & co.' },
  agent:    { label: 'Fleet Agent',  icon: 'sensors',              chip: 'bg-amber-500/10 text-amber-400',    desc: 'Token used by the on-box agent' },
  secrets:  { label: 'App Secrets',  icon: 'lock',                 chip: 'bg-rose-500/10 text-rose-400',      desc: 'JWT & encryption keys' },
  dns:      { label: 'DNS Records',  icon: 'public',               chip: 'bg-teal-500/10 text-teal-400',      desc: 'Publish these at your registrar' },
}
const categoryOrder = ['mail', 'panel', 'ssh', 'database', 'services', 'agent', 'secrets', 'dns']

const credentialGroups = computed(() => {
  const q = credentialSearch.value.trim().toLowerCase()
  const grouped = {}
  for (const cred of credentials.value) {
    if (q) {
      const meta = categoryMeta[cred.category]
      const valueHay = cred.is_secret ? '' : (cred.value || '')
      const hay = `${cred.label || ''} ${cred.key || ''} ${cred.category || ''} ${meta?.label || ''} ${valueHay}`.toLowerCase()
      if (!hay.includes(q)) continue
    }
    if (!grouped[cred.category]) grouped[cred.category] = []
    grouped[cred.category].push(cred)
  }
  const fallback = (cat) => ({ label: cat, icon: 'key', chip: 'bg-surface-500/10 text-surface-400', desc: '' })
  const result = []
  const seen = new Set()
  for (const cat of categoryOrder) {
    if (grouped[cat]?.length) {
      result.push({ key: cat, ...(categoryMeta[cat] || fallback(cat)), items: grouped[cat] })
      seen.add(cat)
    }
  }
  for (const cat in grouped) {
    if (!seen.has(cat) && grouped[cat]?.length) {
      result.push({ key: cat, ...(categoryMeta[cat] || fallback(cat)), items: grouped[cat] })
    }
  }
  return result
})

const filteredCredentialCount = computed(() =>
  credentialGroups.value.reduce((n, g) => n + g.items.length, 0)
)

// Collapse state. Open by default: Panel Admin + Email Login; everything else closed.
const openGroups = ref({})
const defaultOpen = (key) => key === 'panel' || key === 'mail'
const isGroupOpen = (key) => openGroups.value[key] ?? defaultOpen(key)
// While searching, force groups with matches open so results are visible.
const effectiveOpen = (key) => (credentialSearch.value.trim() !== '' ? true : isGroupOpen(key))
const toggleGroup = (key) => { openGroups.value = { ...openGroups.value, [key]: !isGroupOpen(key) } }
const allGroupsOpen = computed(() =>
  credentialGroups.value.length > 0 && credentialGroups.value.every(g => isGroupOpen(g.key))
)
const setAllGroups = (open) => {
  const next = { ...openGroups.value }
  for (const g of credentialGroups.value) next[g.key] = open
  openGroups.value = next
}

// Audit
const auditData = ref(null)
const loadingAudit = ref(false)
const runningAudit = ref(false)

const fetchAudit = async () => {
  loadingAudit.value = true
  try {
    const response = await api.get(`/api/servers/${route.params.id}/audit`)
    auditData.value = response.data?.audit || null
  } catch (error) {
    console.error('Failed to load audit', error)
  } finally {
    loadingAudit.value = false
  }
}

// DNS records - read LIVE from the box's panel DB (dns_domains/dns_records).
const dnsRecords = ref([])
const dnsDb = ref(null)
const loadingDns = ref(false)
const reseedingDns = ref(false)

const fetchDns = async () => {
  // Docker boxes don't run PowerDNS — the compose stack has no DNS server, so the
  // native "read the panel DB's dns_records" probe (and Re-seed) don't apply. DNS
  // for these servers lives at the registrar; the records to publish are in the
  // Server Credentials (DNS) section. Skip the host-DB lookup entirely.
  if (isDocker.value) {
    dnsRecords.value = []
    dnsDb.value = null
    return
  }
  loadingDns.value = true
  try {
    const response = await api.get(`/api/servers/${route.params.id}/dns`)
    dnsRecords.value = response.data?.records || []
    dnsDb.value = response.data?.db || null
  } catch (error) {
    // Non-fatal: the box may be unreachable (e.g. before Test Connection).
    dnsRecords.value = []
  } finally {
    loadingDns.value = false
  }
}

const reseedDns = async () => {
  reseedingDns.value = true
  try {
    const response = await api.post(`/api/servers/${route.params.id}/dns/reseed`)
    dnsRecords.value = response.data?.records || dnsRecords.value
    dnsDb.value = response.data?.db || dnsDb.value
    toast.success(response.data?.message || 'DNS re-seeded')
  } catch (error) {
    toast.error(error.response?.data?.message || error.response?.data?.error || 'DNS re-seed failed')
  } finally {
    reseedingDns.value = false
  }
}

const copyDnsValue = async (value) => {
  try {
    await navigator.clipboard.writeText(value)
    toast.success('Copied to clipboard')
  } catch (e) {
    toast.error('Could not copy')
  }
}

// CPGuard - live install state read over SSH; license keys are IP-bound so
// each server carries its own (stored encrypted on the server row).
const cpguard = ref(null)
const loadingCpguard = ref(false)
const installingCpguard = ref(false)
const cpguardKeyInput = ref('')

const fetchCpguard = async () => {
  loadingCpguard.value = true
  try {
    const response = await api.get(`/api/servers/${route.params.id}/cpguard`)
    cpguard.value = response.data
  } catch (error) {
    // Non-fatal: the box may be unreachable (e.g. before Test Connection).
    cpguard.value = null
  } finally {
    loadingCpguard.value = false
  }
}

const installCpguard = async () => {
  const key = cpguardKeyInput.value.trim()
  if (!key && !cpguard.value?.has_license) {
    toast.error('Enter the CPGuard license key for this server first')
    return
  }
  installingCpguard.value = true
  try {
    const payload = key ? { license_key: key } : {}
    const response = await api.post(`/api/servers/${route.params.id}/cpguard/install`, payload)
    toast.success(response.message || 'CPGuard installed')
    cpguardKeyInput.value = ''
    await fetchCpguard()
  } catch (error) {
    toast.error(error.response?.data?.message || error.response?.data?.error || error.message || 'CPGuard install failed')
  } finally {
    installingCpguard.value = false
  }
}

// Docker container health - live `docker compose ps` over SSH for
// Docker-provisioned boxes, so the Services + Versions panels reflect the real
// container stack instead of the native systemd/version model that never applies here.
const dockerStatus = ref(null)
const loadingDockerStatus = ref(false)

const fetchDockerStatus = async () => {
  loadingDockerStatus.value = true
  try {
    const response = await api.get(`/api/servers/${route.params.id}/docker-status`)
    dockerStatus.value = response.data
  } catch (error) {
    // Non-fatal: box may be unreachable, or not a Docker deploy.
    dockerStatus.value = null
  } finally {
    loadingDockerStatus.value = false
  }
}

const dnsTypeClass = (type) => {
  const t = (type || '').toUpperCase()
  if (t === 'TXT') return 'bg-amber-500/10 text-amber-500'
  if (t === 'MX') return 'bg-violet-500/10 text-violet-400'
  if (t === 'A' || t === 'AAAA') return 'bg-blue-500/10 text-blue-400'
  if (t === 'CNAME' || t === 'SRV') return 'bg-teal-500/10 text-teal-400'
  return 'bg-surface-500/10 text-surface-400'
}

const runAudit = async () => {
  runningAudit.value = true
  try {
    const response = await api.post(`/api/servers/${route.params.id}/audit`)
    auditData.value = response.data?.audit || null
    const passed = auditData.value?.passed || 0
    const failed = auditData.value?.failed || 0
    if (failed > 0) {
      toast.error(`Audit completed: ${failed} check(s) failed`)
    } else {
      toast.success(`Audit passed: ${passed} checks OK`)
    }
  } catch (error) {
    toast.error(error.response?.data?.error || 'Audit failed')
  } finally {
    runningAudit.value = false
  }
}

const fixingCheck = ref(null)
const fixingAll = ref(false)

const fixAuditCheck = async (check) => {
  if (!check.fix_action) return
  fixingCheck.value = check.name
  try {
    const response = await api.post(`/api/servers/${route.params.id}/audit/fix`, {
      action: check.fix_action,
      params: check.fix_params || {},
    })
    toast.success(response.message || 'Fix applied')
    check.status = 'pass'
    check.detail = response.data?.message || 'Fixed'
    delete check.fix_action
    delete check.fix_params
  } catch (error) {
    const data = error.response?.data
    const msg = data?.error || 'Fix failed'
    const output = data?.output
    toast.error(output ? `${msg}\n\nSSH output:\n${output}` : msg, { duration: 8000 })
  } finally {
    fixingCheck.value = null
  }
}

const fixAllAuditIssues = async () => {
  fixingAll.value = true
  try {
    const response = await api.post(`/api/servers/${route.params.id}/audit/fix`, {
      action: 'fix_all',
      params: {},
    })
    toast.success(response.message || 'Fixes applied')
    runAudit()
  } catch (error) {
    const data = error.response?.data
    const msg = data?.error || 'Fix all failed'
    const output = data?.output
    toast.error(output ? `${msg}\n\nSSH output:\n${output}` : msg, { duration: 8000 })
  } finally {
    fixingAll.value = false
  }
}

const hasFixableIssues = computed(() => {
  if (!auditData.value?.checks) return false
  return auditData.value.checks.some(c => c.fix_action && c.status !== 'pass')
})

const auditStatusIcon = (status) => {
  if (status === 'pass') return 'check_circle'
  if (status === 'fail') return 'cancel'
  return 'warning'
}

const auditStatusColor = (status) => {
  if (status === 'pass') return 'text-green-500'
  if (status === 'fail') return 'text-red-500'
  return 'text-amber-500'
}

const auditChecksByCategory = computed(() => {
  if (!auditData.value?.checks) return {}
  const grouped = {}
  const labels = {
    services: 'Services',
    packages: 'Packages',
    database: 'Databases',
    filesystem: 'File System',
    ssl: 'SSL Certificates',
    http: 'HTTP Connectivity',
    firewall: 'Firewall',
    application: 'Applications',
    agent: 'Fleet Agent',
    security: 'Security',
  }
  for (const check of auditData.value.checks) {
    const cat = check.category
    if (!grouped[cat]) grouped[cat] = { label: labels[cat] || cat, items: [] }
    grouped[cat].items.push(check)
  }
  return grouped
})

const viewLog = async (deployment) => {
  selectedDeployment.value = deployment
  showLogModal.value = true
  loadingLog.value = true
  deploymentLog.value = ''
  
  try {
    const response = await api.get(`/api/deployments/${deployment.id}/logs?offset=0`)
    deploymentLog.value = response.data?.content || 'No log available'
  } catch (error) {
    deploymentLog.value = 'Failed to load log'
    toast.error('Failed to load deployment log')
  } finally {
    loadingLog.value = false
  }
}

// Pull the provisioning log the FM wrote ONTO the box (/var/log/fleet/). Lets the
// operator read exactly what happened (incl. the SSH-harden diagnostics) even when
// the dashboard's own deployment view is stale.
const fetchingServerLog = ref(false)
const fetchServerLog = async () => {
  fetchingServerLog.value = true
  selectedDeployment.value = { id: 'on-box', type: 'on_box_log', status: 'from server' }
  showLogModal.value = true
  loadingLog.value = true
  deploymentLog.value = ''
  try {
    const response = await api.get(`/api/servers/${route.params.id}/provision-log`)
    const d = response.data || {}
    let header = ''
    if (d.path) header += `# Source: ${d.path}\n`
    if (d.files?.length) header += `# Available on box: ${d.files.join(', ')}\n`
    if (header) header += '\n'
    deploymentLog.value = header + (d.log || 'No on-box log available')
  } catch (error) {
    showLogModal.value = false
    toast.error(error.response?.data?.error || error.message || 'Could not fetch the server log')
  } finally {
    loadingLog.value = false
    fetchingServerLog.value = false
  }
}

const downloadLog = () => {
  if (!selectedDeployment.value || !deploymentLog.value) return
  
  const blob = new Blob([deploymentLog.value], { type: 'text/plain' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `deployment-${selectedDeployment.value.id}-${server.value?.name || 'server'}.log`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

const getDeploymentStatusClass = (status) => {
  switch (status) {
    case 'success': return 'badge-success'
    case 'failed': return 'badge-danger'
    case 'running': return 'badge-warning'
    case 'pending': return 'badge-neutral'
    default: return 'badge-neutral'
  }
}

const getDeploymentTypeLabel = (type) => {
  const labels = {
    'full_provision': 'Full Provision',
    'config_only': 'Config Only',
    'packages_config': 'Packages + Config',
    'agent_update': 'Agent Update',
    'app_update': 'App Update',
    'wipe': 'Wipe',
    'on_box_log': 'On-box Provisioning Log'
  }
  return labels[type] || type
}

const getServiceStatusClass = (status) => {
  switch (status) {
    case 'running': return 'text-green-400'
    case 'stopped': return 'text-red-400'
    case 'disabled': return 'text-surface-500'
    case 'error': return 'text-red-400'
    default: return 'text-surface-400'
  }
}

// Normalised view of the latest live Deployment Audit, keyed by squashed service
// name (e.g. "MailSync Server" -> "mailsyncserver"). Used as the authoritative
// fallback for the Services panel so it can never contradict the audit below it.
const auditServiceStatus = computed(() => {
  const map = {}
  const checks = auditData.value?.checks || []
  for (const c of checks) {
    if (!c || !c.name) continue
    const key = String(c.name).toLowerCase().replace(/[^a-z0-9]/g, '')
    let s = 'stopped'
    if (c.status === 'pass') s = 'running'
    else if (c.status === 'warning') s = /not installed/i.test(c.detail || '') ? 'disabled' : 'stopped'
    else if (c.status === 'fail') s = 'error'
    map[key] = s
  }
  return map
})

// Service rows for the panel. Priority: the live on-load SSH probe
// (server.health.*_status, refreshed every page open) wins; when the probe has
// no value yet, fall back to the latest live audit result. This keeps the panel
// consistent with the Deployment Audit ("PASS below" => green above).
const SERVICE_PANEL = [
  { label: 'OpenLiteSpeed',   healthKey: 'openlitespeed_status', audit: ['openlitespeed'] },
  { label: 'MariaDB',         healthKey: 'mariadb_status',       audit: ['mariadb'] },
  { label: 'Redis',           healthKey: 'redis_status',         audit: ['redis'] },
  { label: 'Meilisearch',     healthKey: 'meilisearch_status',   audit: ['meilisearch'] },
  { label: 'Postfix',         healthKey: 'postfix_status',       audit: ['postfix'] },
  { label: 'Dovecot',         healthKey: 'dovecot_status',       audit: ['dovecot'] },
  { label: 'SpamAssassin',    healthKey: 'spamassassin_status',  audit: ['spamassassin'] },
  { label: 'Fail2ban',        healthKey: 'fail2ban_status',      audit: ['fail2ban'] },
  { label: 'FirewallD',       healthKey: 'firewalld_status',     audit: ['firewalld', 'firewalldactive'] },
  { label: 'Collab Server',   healthKey: 'collab_status',        audit: ['collabserver'] },
  { label: 'MailSync Server', healthKey: 'mailsync_status',      audit: ['mailsyncserver'] },
  { label: 'Fleet Agent',     healthKey: 'fleet_agent_status',   audit: ['fleetagentrunning', 'fleetagent'] },
]

const serviceList = computed(() => {
  const health = server.value?.health || {}
  const audit = auditServiceStatus.value
  return SERVICE_PANEL.map((s) => {
    let status = health[s.healthKey] || null
    if (!status) {
      for (const k of s.audit) {
        if (audit[k]) { status = audit[k]; break }
      }
    }
    return { label: s.label, status }
  })
})

// A box is "Docker" once a Docker provision has recorded an image tag on it.
// This flips the Versions + Services panels from the native model (systemd
// services, panel/email/agent versions) to the container reality.
const isDocker = computed(() => !!server.value?.deployed_image_tag)

// The containers this stack ships, in display order. Infra images (mariadb,
// redis, meilisearch) are pinned upstream; the app tier (web/collab/mailsync/
// mail) carries our deployed_image_tag.
const DOCKER_SERVICE_PANEL = [
  { key: 'web',         label: 'Web (OpenLiteSpeed)',   app: true },
  { key: 'collab',      label: 'Collab Server',         app: true },
  { key: 'mailsync',    label: 'MailSync Server',       app: true },
  { key: 'mail',        label: 'Mail (Postfix/Dovecot)', app: true },
  { key: 'mariadb',     label: 'MariaDB',               app: false },
  { key: 'redis',       label: 'Redis',                 app: false },
  { key: 'meilisearch', label: 'Meilisearch',           app: false },
]

// Map a compose ps state/health pair onto the panel's status vocabulary
// (running | error | disabled | stopped) used by the icon + colour helpers.
const dockerContainerStatus = (svc) => {
  if (!svc) return null
  const state = (svc.state || '').toLowerCase()
  const health = (svc.health || '').toLowerCase()
  if (state === 'running') {
    if (health === '' || health === 'healthy') return 'running'
    if (health === 'starting') return 'disabled' // amber "coming up"
    return 'error' // unhealthy
  }
  if (state === 'restarting') return 'disabled'
  return 'error' // exited / dead / created / paused
}

const dockerServiceList = computed(() => {
  const svcs = dockerStatus.value?.services || {}
  return DOCKER_SERVICE_PANEL
    .filter((s) => svcs[s.key] || dockerStatus.value?.reachable) // hide unknowns only when we truly have no data
    .map((s) => ({
      key: s.key,
      label: s.label,
      app: s.app,
      status: dockerContainerStatus(svcs[s.key]),
      health: (svcs[s.key]?.health || '').toLowerCase(),
    }))
})

const formatDate = (date) => {
  if (!date) return 'Never'
  return new Date(date).toLocaleString()
}

// How long a deployment took (or has been running). MySQL datetimes are parsed
// as ISO by swapping the space for 'T'.
const formatDeploymentDuration = (deployment) => {
  if (!deployment?.started_at) return ''
  const start = new Date(deployment.started_at.replace(' ', 'T')).getTime()
  const endSrc = deployment.completed_at || (deployment.status === 'running' ? new Date() : null)
  if (!endSrc) return ''
  const end = typeof endSrc === 'string' ? new Date(endSrc.replace(' ', 'T')).getTime() : endSrc.getTime()
  if (isNaN(start) || isNaN(end) || end < start) return ''
  const sec = Math.round((end - start) / 1000)
  if (sec < 60) return `${sec}s`
  const min = Math.floor(sec / 60)
  return `${min}m ${sec % 60}s`
}

const openDeployModal = (type = null) => {
  showDeployMenu.value = false
  deployType.value = type
  resumeDeployId.value = null
  showDeployModal.value = true
}

const openDeployProgress = (deployment) => {
  resumeDeployId.value = deployment.id
  deployType.value = null
  showDeployModal.value = true
}

const handleDeployed = (result) => {
  toast.success('Deployment started')
  // Refresh server data
  fetchServer()
}

// Delete Server Modal (requires typing DELETE to confirm)
const showDeleteModal = ref(false)
const deleteConfirmText = ref('')
const deletingServer = ref(false)
const canDeleteServer = computed(() => deleteConfirmText.value.trim().toUpperCase() === 'DELETE')

const openDeleteModal = () => {
  deleteConfirmText.value = ''
  showDeleteModal.value = true
}

const closeDeleteModal = () => {
  if (deletingServer.value) return
  showDeleteModal.value = false
  deleteConfirmText.value = ''
}

const confirmDeleteServer = async () => {
  if (!canDeleteServer.value || deletingServer.value) return

  deletingServer.value = true
  try {
    await api.delete(`/api/servers/${server.value.id}`)
    toast.success('Server deleted')
    showDeleteModal.value = false
    router.push('/servers')
  } catch (error) {
    toast.error('Failed to delete server')
  } finally {
    deletingServer.value = false
  }
}

const sshSystemInfo = ref(null)

// Operator authorized SSH public key (pxr). Empty = use the fleet default.
const authorizedKeyInput = ref('')
const savingAuthorizedKey = ref(false)

const saveAuthorizedKey = async () => {
  savingAuthorizedKey.value = true
  try {
    const response = await api.post(`/api/servers/${server.value.id}/authorized-key`, {
      public_key: (authorizedKeyInput.value || '').trim(),
    })
    if (response.success !== false) {
      const msg = response.data?.message || 'Authorized key saved'
      if (server.value) server.value.ssh_authorized_key = (authorizedKeyInput.value || '').trim() || null
      toast.success(msg)
    } else {
      toast.error(response.error || 'Failed to save key')
    }
  } catch (error) {
    toast.error(error.response?.data?.error || error.message || 'Failed to save authorized key')
  } finally {
    savingAuthorizedKey.value = false
  }
}

const testConnection = async (silent = false) => {
  testingConnection.value = true
  connectionStatus.value = null
  connectionError.value = ''

  try {
    const response = await api.post(`/api/servers/${server.value.id}/test-connection`)
    if (response.data?.connected) {
      connectionStatus.value = 'success'
      connectionError.value = ''
      // The backend probes the real SSH profile and may have auto-corrected the
      // stored port/user/auth (e.g. after a deploy hardened the box to pxr@1985).
      // Reflect that immediately so PORT / USER / AUTH and the SSH command update
      // without a page reload.
      const detected = response.data.detected || null
      if (detected && server.value) {
        if (detected.ssh_port) server.value.ssh_port = detected.ssh_port
        if (detected.ssh_user) server.value.ssh_user = detected.ssh_user
        if (detected.ssh_auth_method) server.value.ssh_auth_method = detected.ssh_auth_method
      }
      const info = response.data.server_info || null
      sshSystemInfo.value = info
      if (info && server.value) {
        // Reflect the freshly-probed OS (also persisted server-side).
        if (info.os) server.value.os_info = info.os
        // Feed the live snapshot straight into the Health card so CPU / Memory /
        // Disk show immediately, without waiting for the agent's first heartbeat.
        if (info.cpu || info.memory || info.disk) {
          server.value.health = {
            ...(server.value.health || {}),
            ...(info.cpu && {
              cpu_load_1m: info.cpu.load_1m,
              cpu_load_5m: info.cpu.load_5m,
              cpu_load_15m: info.cpu.load_15m,
            }),
            ...(info.memory && {
              memory_percent: info.memory.percent,
              memory_total_mb: info.memory.total_mb,
              memory_used_mb: info.memory.used_mb,
            }),
            ...(info.disk && {
              disk_percent: info.disk.percent,
              disk_total_gb: info.disk.total_gb,
              disk_used_gb: info.disk.used_gb,
            }),
          }
        }
        // Live service states from the same probe, so the Services panel reflects
        // reality instead of a stale agent heartbeat (running/stopped/disabled).
        if (info.services) {
          const svcMap = {
            openlitespeed: 'openlitespeed_status', mariadb: 'mariadb_status',
            redis: 'redis_status', meilisearch: 'meilisearch_status',
            postfix: 'postfix_status', dovecot: 'dovecot_status',
            spamassassin: 'spamassassin_status', fail2ban: 'fail2ban_status',
            firewalld: 'firewalld_status', collab: 'collab_status',
            mailsync: 'mailsync_status', fleet_agent: 'fleet_agent_status',
          }
          const h = { ...(server.value.health || {}) }
          for (const k in svcMap) {
            if (info.services[k]) h[svcMap[k]] = info.services[k]
          }
          server.value.health = h
        }
      }
      if (!silent) toast.success(response.data.message || 'Connection successful')
    } else {
      connectionStatus.value = 'failed'
      sshSystemInfo.value = null
      connectionError.value = response.data?.message || 'Connection failed'
      if (!silent) toast.error(connectionError.value)
    }
  } catch (error) {
    connectionStatus.value = 'failed'
    sshSystemInfo.value = null
    connectionError.value = error.response?.data?.message || error.message || 'Connection test failed'
    if (!silent) toast.error(connectionError.value)
  } finally {
    testingConnection.value = false
  }
}

// Reset Status Modal
const resettingStatus = ref(false)
const showResetModal = ref(false)

const openResetModal = () => {
  showResetModal.value = true
}

const resetServerStatus = async () => {
  showResetModal.value = false
  resettingStatus.value = true
  try {
    const response = await api.post(`/api/servers/${server.value.id}/reset-status`)
    if (response.success) {
      toast.success('Server status reset successfully')
      fetchServer()
    } else {
      toast.error(response.error || 'Failed to reset status')
    }
  } catch (error) {
    toast.error('Failed to reset server status')
  } finally {
    resettingStatus.value = false
  }
}

const maskPassword = (password) => {
  if (!password) return '(not set)'
  return '*'.repeat(Math.min(password.length, 12))
}

// Live SSH connection (port + auth method are kept in sync by the agent heartbeat
// after a hardened/cloned sshd_config may have moved the port / disabled passwords).
const sshAuthIsKey = computed(() => server.value?.ssh_auth_method === 'key')

// The operator's LOCAL private-key path (on their own machine), dropped into the
// copy-paste command. Set ONCE globally in Settings -> Fleet Access (server-side,
// applies to every server/browser); a per-server override (this browser) wins when
// a single box needs a different key. Effective = override || global || legacy.
const LEGACY_KEY = 'fleet_ssh_local_key_path'
const globalKeyPath = ref('')      // server-side default from Settings -> Fleet Access
const keyPathOverride = ref('')    // per-server override (this browser only)

const overrideStorageKey = () => `fleet_ssh_keypath_override_${server.value?.id ?? route.params.id ?? ''}`
const loadKeyPathOverride = () => {
  try { keyPathOverride.value = localStorage.getItem(overrideStorageKey()) || '' }
  catch (e) { keyPathOverride.value = '' }
}
watch(keyPathOverride, (v) => {
  try { localStorage.setItem(overrideStorageKey(), (v || '').trim()) } catch (e) { /* ignore */ }
})

const effectiveKeyPath = computed(() => {
  const o = (keyPathOverride.value || '').trim()
  if (o) return o
  const g = (globalKeyPath.value || '').trim()
  if (g) return g
  try { return (localStorage.getItem(LEGACY_KEY) || '').trim() } catch (e) { return '' }
})

// The SSH command needs the private key FILE, but operators often paste the key
// FOLDER (e.g. "...\ssh_keys\vps" instead of "...\ssh_keys\vps\vps_sftp_key").
// Keys are commonly extensionless, so we can't safely auto-append a filename -
// instead we flag last segments that don't look key-like and warn the operator.
const keyPathLooksLikeDir = computed(() => {
  const kp = (effectiveKeyPath.value || '').trim()
  if (!kp) return false
  const seg = (kp.replace(/[\\/]+$/, '').split(/[\\/]/).pop() || '')
  if (!seg) return true
  return !/(key|\.pem|\.ppk|\.pk|identity|id_[a-z0-9]+)$/i.test(seg)
})

const sshCommand = computed(() => {
  if (!server.value) return ''
  const port = server.value.ssh_port || 22
  const user = server.value.ssh_user || 'root'
  const host = server.value.ip_address || ''
  let keyOpt = ''
  if (sshAuthIsKey.value) {
    const kp = effectiveKeyPath.value
    if (kp) {
      // Quote paths containing spaces (e.g. Windows "D:\04 Work\...").
      keyOpt = /\s/.test(kp) ? ` -i "${kp}"` : ` -i ${kp}`
    } else {
      keyOpt = ' -i <your-key>'
    }
  }
  return `ssh -p ${port}${keyOpt} ${user}@${host}`
})

const copySshCommand = () => copyCredential(sshCommand.value, 'SSH command')

// Editable SSH connection profile - lets the operator correct a stale row (e.g.
// a hardened box that the FM hasn't re-verified yet) so PORT/USER/AUTH and the
// command reflect reality immediately. Persisted via PUT /api/servers/{id}.
const editingSsh = ref(false)
const savingSsh = ref(false)
const sshEdit = ref({ port: 22, user: 'root', auth_method: 'key' })

const startEditSsh = () => {
  sshEdit.value = {
    port: server.value?.ssh_port || 22,
    user: server.value?.ssh_user || 'root',
    auth_method: server.value?.ssh_auth_method || 'key',
  }
  editingSsh.value = true
}
const cancelEditSsh = () => { editingSsh.value = false }
const useHardenedProfile = () => {
  sshEdit.value = { port: 1985, user: 'pxr', auth_method: 'key' }
}
const saveSsh = async () => {
  savingSsh.value = true
  try {
    const port = Number(sshEdit.value.port) || 22
    const user = (sshEdit.value.user || 'root').trim()
    const auth = sshEdit.value.auth_method
    await api.put(`/api/servers/${server.value.id}`, {
      ssh_port: port,
      ssh_user: user,
      ssh_auth_method: auth,
    })
    server.value.ssh_port = port
    server.value.ssh_user = user
    server.value.ssh_auth_method = auth
    editingSsh.value = false
    toast.success('SSH connection updated')
  } catch (error) {
    toast.error(error.response?.data?.error || error.message || 'Failed to update SSH connection')
  } finally {
    savingSsh.value = false
  }
}

// Fleet Manager CONNECTION key: the PRIVATE key the panel uses to reach pxr. It is
// stored fleet-wide & encrypted (same store as Settings -> Fleet Access) but is
// editable here too, so you can give the FM your passphrase-protected key without
// leaving the page. Required when the key has a passphrase or the per-server key
// was lost - otherwise Test Connection can't log in to a hardened box.
const mgmtKey = ref({ configured: false, has_passphrase: false, fingerprint: null, source: 'none' })
const loadingMgmtKey = ref(false)
const showSshSetup = ref(false)
const showMgmtKeyForm = ref(false)
const mgmtPrivateKey = ref('')
const mgmtPassphrase = ref('')
const savingMgmtKey = ref(false)

const loadMgmtKey = async () => {
  loadingMgmtKey.value = true
  try {
    const res = await api.get('/api/settings/ssh')
    mgmtKey.value = res.data || mgmtKey.value
    globalKeyPath.value = res.data?.default_local_key_path || ''
  } catch (e) {
    /* non-fatal */
  } finally {
    loadingMgmtKey.value = false
  }
}

const saveMgmtKey = async () => {
  if (!mgmtPrivateKey.value.trim()) {
    toast.error('Paste your private key first')
    return
  }
  savingMgmtKey.value = true
  try {
    await api.put('/api/settings/ssh', {
      private_key: mgmtPrivateKey.value,
      passphrase: mgmtPassphrase.value,
    })
    toast.success('Connection key saved - testing...')
    mgmtPrivateKey.value = ''
    mgmtPassphrase.value = ''
    showMgmtKeyForm.value = false
    await loadMgmtKey()
    // Immediately try to connect with the new key so the card self-heals.
    testConnection()
  } catch (error) {
    toast.error(error.response?.data?.error || error.message || 'Failed to save key')
  } finally {
    savingMgmtKey.value = false
  }
}

// Close dropdown when clicking outside
const closeDropdown = (e) => {
  if (showDeployMenu.value && !e.target.closest('.relative')) {
    showDeployMenu.value = false
  }
}

// Load the server record and immediately pull a fresh live SSH snapshot (OS +
// CPU/Memory/Disk + uptime) without the operator clicking Test Connection.
// Silent (no toasts) and only for remote boxes - local hosts use the agent.
const loadServer = async () => {
  // Clear the previous server's live snapshot so stale data never flashes when
  // navigating between server pages.
  sshSystemInfo.value = null
  connectionStatus.value = null
  await fetchServer()
  if (server.value && !server.value.is_local) {
    testConnection(true)
  }
}

// Re-probe whenever the tab regains focus so the data is fresh on return.
const onVisibility = () => {
  if (document.visibilityState === 'visible'
      && server.value && !server.value.is_local && !testingConnection.value) {
    testConnection(true)
  }
}

// Keep the live health fresh while the page stays open (visible tab only).
let healthTimer = null

onMounted(async () => {
  await loadServer()
  loadKeyPathOverride()
  loadMgmtKey()
  document.addEventListener('click', closeDropdown)
  document.addEventListener('visibilitychange', onVisibility)
  healthTimer = setInterval(() => {
    if (document.visibilityState === 'visible'
        && server.value && !server.value.is_local && !testingConnection.value) {
      testConnection(true)
    }
  }, 45000)
})

// Vue reuses this component when only the :id route param changes, so onMounted
// won't re-fire. Watch the id so opening a different server always reloads +
// re-probes fresh.
watch(() => route.params.id, (newId, oldId) => {
  if (newId && newId !== oldId) {
    loadServer()
    loadKeyPathOverride()
  }
})

onUnmounted(() => {
  document.removeEventListener('click', closeDropdown)
  document.removeEventListener('visibilitychange', onVisibility)
  if (healthTimer) {
    clearInterval(healthTimer)
    healthTimer = null
  }
})
</script>

<template>
  <div class="animate-fadeIn">
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="spinner w-10 h-10"></div>
    </div>

    <template v-else-if="server">
      <!-- Header with Actions -->
      <div class="flex flex-wrap items-center gap-4 mb-6">
        <button @click="router.push('/servers')" class="btn btn-ghost btn-sm">
          <span class="material-symbols-rounded">arrow_back</span>
        </button>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold truncate">{{ server.name }}</h1>
            <span :class="[
              'badge shrink-0',
              server.status === 'active' ? 'badge-success' :
              server.status === 'error' ? 'badge-danger' :
              server.status === 'provisioning' ? 'badge-warning' : 'badge-neutral'
            ]">
              {{ server.status }}
            </span>
            <!-- Reset button for non-active states -->
            <button 
              v-if="server.status !== 'active'"
              @click="openResetModal"
              :disabled="resettingStatus"
              class="btn btn-ghost btn-xs text-amber-500 hover:text-amber-400 hover:bg-amber-500/10"
              title="Reset to active status"
            >
              <span v-if="resettingStatus" class="spinner w-4 h-4"></span>
              <span v-else class="material-symbols-rounded text-lg">refresh</span>
            </button>
          </div>
          <div class="flex items-center gap-2 flex-wrap">
            <p class="text-surface-500 dark:text-surface-400">{{ server.ip_address }}</p>
            <span
              v-if="server.deployed_image_tag"
              class="inline-flex items-center gap-1 text-xs font-mono px-2 py-0.5 rounded-md bg-blue-500/10 text-blue-600 dark:text-blue-400"
              title="Docker image version currently deployed"
            >
              <span class="material-symbols-rounded text-sm">inventory_2</span>
              {{ server.deployed_image_tag }}
            </span>
          </div>
        </div>
        
        <!-- Action Buttons in Header -->
        <div class="flex items-center gap-2 flex-wrap">
          <!-- Deploy Dropdown -->
          <div class="relative">
            <button 
              @click="showDeployMenu = !showDeployMenu"
              class="btn btn-primary btn-sm"
            >
              <span class="material-symbols-rounded">rocket_launch</span>
              Deploy
              <span class="material-symbols-rounded text-sm">expand_more</span>
            </button>
            
            <!-- Dropdown Menu -->
            <div 
              v-if="showDeployMenu"
              class="absolute top-full right-0 mt-2 w-64 bg-white dark:bg-surface-700 rounded-xl shadow-xl border border-surface-200 dark:border-surface-600 overflow-hidden z-50"
            >
              <!-- Docker (default workflow) -->
              <p class="px-4 pt-2.5 pb-1 text-[10px] font-semibold uppercase tracking-wider text-surface-400 dark:text-surface-500">Docker</p>
              <button 
                @click="openDeployModal('docker_provision')"
                class="w-full flex items-center gap-3 px-4 py-3 hover:bg-surface-100 dark:hover:bg-surface-600 transition-colors text-left"
              >
                <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">inventory_2</span>
                <div>
                  <p class="font-medium text-surface-900 dark:text-surface-100">Docker Provision</p>
                  <p class="text-xs text-surface-500 dark:text-surface-400">Deploy full stack via Docker Compose + SSL</p>
                </div>
              </button>
              <button 
                @click="openDeployModal('docker_update')"
                class="w-full flex items-center gap-3 px-4 py-3 hover:bg-surface-100 dark:hover:bg-surface-600 transition-colors text-left"
              >
                <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">upgrade</span>
                <div>
                  <p class="font-medium text-surface-900 dark:text-surface-100">Docker Update</p>
                  <p class="text-xs text-surface-500 dark:text-surface-400">Roll app services to a chosen image version</p>
                </div>
              </button>

              <!-- Native (legacy, non-Docker installs) -->
              <div class="border-t border-surface-200 dark:border-surface-600 mt-1"></div>
              <p class="px-4 pt-2.5 pb-1 text-[10px] font-semibold uppercase tracking-wider text-surface-400 dark:text-surface-500">Native (legacy)</p>
              <button 
                @click="openDeployModal('full_provision')"
                class="w-full flex items-center gap-3 px-4 py-3 hover:bg-surface-100 dark:hover:bg-surface-600 transition-colors text-left"
              >
                <span class="material-symbols-rounded text-primary-600 dark:text-primary-400">build</span>
                <div>
                  <p class="font-medium text-surface-900 dark:text-surface-100">Full Provision</p>
                  <p class="text-xs text-surface-500 dark:text-surface-400">Native install (packages + configs + apps)</p>
                </div>
              </button>
              <button 
                @click="openDeployModal('config_only')"
                class="w-full flex items-center gap-3 px-4 py-3 hover:bg-surface-100 dark:hover:bg-surface-600 transition-colors text-left"
              >
                <span class="material-symbols-rounded text-green-600 dark:text-green-400">settings</span>
                <div>
                  <p class="font-medium text-surface-900 dark:text-surface-100">Config Only</p>
                  <p class="text-xs text-surface-500 dark:text-surface-400">Apply configs, restart services</p>
                </div>
              </button>
              <button 
                @click="openDeployModal('packages_config')"
                class="w-full flex items-center gap-3 px-4 py-3 hover:bg-surface-100 dark:hover:bg-surface-600 transition-colors text-left"
              >
                <span class="material-symbols-rounded text-amber-600 dark:text-amber-400">deployed_code</span>
                <div>
                  <p class="font-medium text-surface-900 dark:text-surface-100">Packages + Config</p>
                  <p class="text-xs text-surface-500 dark:text-surface-400">Install packages and configs</p>
                </div>
              </button>
              <button 
                @click="openDeployModal('app_update')"
                class="w-full flex items-center gap-3 px-4 py-3 hover:bg-surface-100 dark:hover:bg-surface-600 transition-colors text-left"
              >
                <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">system_update</span>
                <div>
                  <p class="font-medium text-surface-900 dark:text-surface-100">App Update</p>
                  <p class="text-xs text-surface-500 dark:text-surface-400">Update code only, preserve configs</p>
                </div>
              </button>
            </div>
          </div>
          
          <button @click="fetchServer" class="btn btn-secondary btn-sm" title="Refresh">
            <span class="material-symbols-rounded">refresh</span>
          </button>
          
          <button @click="openDeleteModal" class="btn btn-danger btn-sm" title="Delete server">
            <span class="material-symbols-rounded">delete</span>
          </button>
        </div>
      </div>

      <!-- Reset Status Modal -->
      <Teleport to="body">
        <Transition name="modal">
          <div v-if="showResetModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
            <div class="bg-surface-800 rounded-2xl w-full max-w-sm shadow-2xl border border-surface-700 overflow-hidden">
              <!-- Header -->
              <div class="bg-gradient-to-r from-amber-600 to-orange-600 p-5">
                <div class="flex items-center gap-3">
                  <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center">
                    <span class="material-symbols-rounded text-2xl text-white">refresh</span>
                  </div>
                  <div>
                    <h3 class="text-lg font-bold text-white">Reset Server Status</h3>
                    <p class="text-amber-100 text-sm">{{ server.name }}</p>
                  </div>
                </div>
              </div>
              
              <!-- Content -->
              <div class="p-5">
                <p class="text-surface-300 mb-4">
                  This will reset the server status to <span class="font-semibold text-green-400">"active"</span> and cancel any stuck deployments.
                </p>
                <div class="bg-surface-700/50 rounded-lg p-3 text-sm text-surface-400">
                  <span class="material-symbols-rounded text-amber-400 text-base align-middle mr-1">info</span>
                  Use this when a deployment is stuck or the server status is incorrect.
                </div>
              </div>
              
              <!-- Actions -->
              <div class="flex gap-3 p-5 pt-0">
                <button @click="showResetModal = false" class="btn btn-secondary flex-1">
                  Cancel
                </button>
                <button @click="resetServerStatus" class="btn bg-amber-600 hover:bg-amber-500 text-white flex-1">
                  <span class="material-symbols-rounded">check</span>
                  Reset Status
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>

      <!-- Delete Server Modal (type DELETE to confirm) -->
      <Teleport to="body">
        <Transition name="modal">
          <div
            v-if="showDeleteModal"
            class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4"
            @click.self="closeDeleteModal"
          >
            <div class="bg-surface-800 rounded-2xl w-full max-w-md shadow-2xl border border-surface-700 overflow-hidden">
              <!-- Header -->
              <div class="bg-gradient-to-r from-red-600 to-rose-600 p-5">
                <div class="flex items-center gap-3">
                  <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center">
                    <span class="material-symbols-rounded text-2xl text-white">delete_forever</span>
                  </div>
                  <div>
                    <h3 class="text-lg font-bold text-white">Delete Server</h3>
                    <p class="text-red-100 text-sm">{{ server?.name }}</p>
                  </div>
                </div>
              </div>

              <!-- Content -->
              <div class="p-5">
                <p class="text-surface-300 mb-4">
                  This permanently removes <span class="font-semibold text-white">{{ server?.name }}</span>
                  from the Fleet Manager. This action <span class="font-semibold text-red-400">cannot be undone</span>.
                </p>
                <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-3 text-sm text-red-200 mb-4">
                  <span class="material-symbols-rounded text-red-400 text-base align-middle mr-1">warning</span>
                  The target machine is not wiped — only its record and connection details here are deleted.
                </div>
                <label class="block text-sm text-surface-300 mb-2">
                  Type <span class="font-mono font-bold text-white">DELETE</span> to confirm
                </label>
                <input
                  v-model="deleteConfirmText"
                  type="text"
                  autocomplete="off"
                  spellcheck="false"
                  placeholder="DELETE"
                  class="w-full bg-surface-900 border border-surface-700 rounded-lg px-3 py-2 text-white font-mono tracking-widest focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500"
                  @keyup.enter="confirmDeleteServer"
                />
              </div>

              <!-- Actions -->
              <div class="flex gap-3 p-5 pt-0">
                <button @click="closeDeleteModal" :disabled="deletingServer" class="btn btn-secondary flex-1">
                  Cancel
                </button>
                <button
                  @click="confirmDeleteServer"
                  :disabled="!canDeleteServer || deletingServer"
                  class="btn btn-danger flex-1 disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  <span v-if="deletingServer" class="material-symbols-rounded animate-spin">progress_activity</span>
                  <span v-else class="material-symbols-rounded">delete_forever</span>
                  {{ deletingServer ? 'Deleting…' : 'Delete Server' }}
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content Area (2 columns) -->
        <div class="lg:col-span-2 space-y-6">
          
          <!-- Server Info + SSH Connection Row -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Server Info -->
            <div class="card">
              <div class="card-header">
                <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                  <span class="material-symbols-rounded text-primary-500">dns</span>
                  Server Info
                </h2>
              </div>
              <div class="card-body space-y-3">
                <div>
                  <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider">Panel Domain</p>
                  <a :href="`https://${server.panel_domain}`" target="_blank" class="text-primary-600 dark:text-primary-400 hover:underline font-medium">
                    {{ server.panel_domain }}
                  </a>
                </div>
                <div>
                  <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider">Email Domain</p>
                  <a :href="`https://${server.email_domain}`" target="_blank" class="text-primary-600 dark:text-primary-400 hover:underline font-medium">
                    {{ server.email_domain }}
                  </a>
                </div>
                <div>
                  <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider">Mail Domain</p>
                  <p class="text-surface-900 dark:text-surface-100 font-medium">{{ server.mail_domain }}</p>
                </div>
                <div class="pt-2 border-t border-surface-200 dark:border-surface-700">
                  <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider">Blueprint</p>
                  <p class="text-surface-900 dark:text-surface-100">{{ server.blueprint_name || 'None' }}</p>
                </div>
              </div>
            </div>

            <!-- SSH Connection -->
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                  <span class="material-symbols-rounded text-green-500">terminal</span>
                  SSH Connection
                </h2>
                <div class="flex items-center gap-2">
                  <span v-if="connectionStatus && !editingSsh" :class="[
                    'badge',
                    connectionStatus === 'success' ? 'badge-success' : 'badge-danger'
                  ]">
                    {{ connectionStatus === 'success' ? 'OK' : 'Failed' }}
                  </span>
                  <button v-if="!editingSsh" @click="startEditSsh" class="btn btn-ghost btn-xs" title="Edit connection (port / user / auth)">
                    <span class="material-symbols-rounded text-base">edit</span>
                  </button>
                </div>
              </div>
              <div class="card-body space-y-3">
                <!-- Read-only view -->
                <template v-if="!editingSsh">
                  <div class="grid grid-cols-2 gap-3">
                    <div>
                      <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider">Host</p>
                      <p class="text-surface-900 dark:text-surface-100 font-mono text-sm">{{ server.ip_address }}</p>
                    </div>
                    <div>
                      <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider">Port</p>
                      <p class="text-surface-900 dark:text-surface-100 font-mono text-sm">{{ server.ssh_port || 22 }}</p>
                    </div>
                  </div>
                  <div class="grid grid-cols-2 gap-3">
                    <div>
                      <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider">User</p>
                      <p class="text-surface-900 dark:text-surface-100 font-mono text-sm">{{ server.ssh_user || 'root' }}</p>
                    </div>
                    <div>
                      <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider">Auth</p>
                      <p class="text-surface-900 dark:text-surface-100 font-mono text-sm flex items-center gap-1">
                        <span class="material-symbols-rounded text-base" :class="sshAuthIsKey ? 'text-green-500' : 'text-amber-500'">
                          {{ sshAuthIsKey ? 'key' : 'password' }}
                        </span>
                        {{ sshAuthIsKey ? 'Key-based' : 'Password' }}
                      </p>
                    </div>
                  </div>
                </template>

                <!-- Edit view -->
                <template v-else>
                  <div class="flex items-center justify-between">
                    <p class="text-xs text-surface-500 dark:text-surface-400">Editing connection profile</p>
                    <button @click="useHardenedProfile" type="button" class="text-xs font-medium text-green-500 hover:text-green-600 flex items-center gap-1">
                      <span class="material-symbols-rounded text-sm">shield</span>
                      Use hardened profile (pxr:1985)
                    </button>
                  </div>
                  <div class="grid grid-cols-2 gap-3">
                    <div>
                      <label class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider">Host</label>
                      <p class="text-surface-900 dark:text-surface-100 font-mono text-sm mt-1.5">{{ server.ip_address }}</p>
                    </div>
                    <div>
                      <label class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider">Port</label>
                      <input v-model.number="sshEdit.port" type="number" min="1" max="65535" class="input w-full text-sm font-mono mt-1" />
                    </div>
                  </div>
                  <div class="grid grid-cols-2 gap-3">
                    <div>
                      <label class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider">User</label>
                      <input v-model="sshEdit.user" type="text" class="input w-full text-sm font-mono mt-1" placeholder="pxr" />
                    </div>
                    <div>
                      <label class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider">Auth</label>
                      <select v-model="sshEdit.auth_method" class="input w-full text-sm mt-1">
                        <option value="key">Key-based</option>
                        <option value="password">Password</option>
                      </select>
                    </div>
                  </div>
                  <div class="flex items-center gap-2">
                    <button @click="saveSsh" :disabled="savingSsh" class="btn btn-primary btn-sm">
                      <span v-if="savingSsh" class="spinner-sm"></span>
                      <span v-else class="material-symbols-rounded text-base">save</span>
                      Save
                    </button>
                    <button @click="cancelEditSsh" :disabled="savingSsh" class="btn btn-ghost btn-sm">Cancel</button>
                  </div>
                </template>
                <!-- Password (password-auth servers only) -->
                <div v-if="!sshAuthIsKey && !editingSsh">
                  <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider">Password</p>
                  <div class="flex items-center gap-1">
                    <p class="text-surface-900 dark:text-surface-100 font-mono text-sm truncate flex-1">
                      {{ showPassword ? (server.ssh_password || '-') : maskPassword(server.ssh_password) }}
                    </p>
                    <button
                      @click="showPassword = !showPassword"
                      class="p-0.5 hover:bg-surface-100 dark:hover:bg-surface-700 rounded transition-colors"
                    >
                      <span class="material-symbols-rounded text-base text-surface-500">
                        {{ showPassword ? 'visibility_off' : 'visibility' }}
                      </span>
                    </button>
                  </div>
                </div>

                <!-- Everyday view: the command you copy + the action you run.
                     All key/path plumbing lives under "Connection setup" below. -->
                <template v-if="!editingSsh">
                  <div>
                    <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider mb-1">SSH Command</p>
                    <div class="flex items-center gap-1 bg-surface-100 dark:bg-surface-900/50 rounded-lg px-2.5 py-1.5">
                      <code class="text-surface-900 dark:text-surface-100 font-mono text-xs truncate flex-1">{{ sshCommand }}</code>
                      <button
                        @click="copySshCommand"
                        class="p-1 hover:bg-surface-200 dark:hover:bg-surface-700 rounded transition-colors shrink-0"
                        title="Copy SSH command"
                      >
                        <span class="material-symbols-rounded text-base text-surface-500">content_copy</span>
                      </button>
                    </div>
                    <p
                      v-if="sshAuthIsKey && keyPathLooksLikeDir"
                      class="text-[11px] text-amber-600 dark:text-amber-400 mt-1 flex items-start gap-1"
                    >
                      <span class="material-symbols-rounded text-sm shrink-0">warning</span>
                      <span>This key path looks like a <strong>folder</strong>. Point <code class="font-mono">-i</code> at the key <em>file</em> (e.g. <span class="font-mono">…\vps_sftp_key</span>) in
                        <RouterLink to="/settings" class="underline">Settings → Fleet Access</RouterLink>
                        or the per-server override below, or the command won't connect.</span>
                    </p>
                  </div>

                  <div class="flex items-center gap-2">
                    <button
                      @click="testConnection()"
                      :disabled="testingConnection"
                      class="btn btn-secondary btn-sm flex-1"
                    >
                      <span v-if="testingConnection" class="spinner w-4 h-4"></span>
                      <span v-else class="material-symbols-rounded">cable</span>
                      {{ testingConnection ? 'Testing...' : 'Test Connection' }}
                    </button>
                    <Transition name="modal">
                      <span v-if="sshSystemInfo?.uptime" class="text-xs text-surface-500 dark:text-surface-400 flex items-center gap-1 shrink-0">
                        <span class="material-symbols-rounded text-sm text-green-500">schedule</span>
                        {{ sshSystemInfo.uptime.replace(/^up\s*/i, '') }}
                      </span>
                    </Transition>
                  </div>

                  <!-- Persistent failure detail: toasts vanish too fast to read the
                       "Tried: ... Last error: ..." line, so pin it here. -->
                  <div
                    v-if="connectionStatus === 'failed' && connectionError"
                    class="rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2"
                  >
                    <div class="flex items-start justify-between gap-2">
                      <p class="text-xs text-red-600 dark:text-red-400 font-mono leading-snug break-words flex-1">{{ connectionError }}</p>
                      <button
                        @click="copyCredential(connectionError, 'Error')"
                        class="p-0.5 hover:bg-red-500/20 rounded transition-colors shrink-0"
                        title="Copy error"
                      >
                        <span class="material-symbols-rounded text-sm text-red-500">content_copy</span>
                      </button>
                    </div>
                  </div>

                  <!-- One collapsible home for every key/path control. Closed by
                       default so the card reads as: identity → command → test. -->
                  <div class="pt-3 border-t border-surface-200 dark:border-surface-700">
                    <button
                      @click="showSshSetup = !showSshSetup"
                      class="w-full flex items-center justify-between text-left group"
                    >
                      <span class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider flex items-center gap-1.5">
                        <span class="material-symbols-rounded text-sm">tune</span>
                        Connection setup &amp; keys
                      </span>
                      <span class="flex items-center gap-1.5">
                        <span
                          class="material-symbols-rounded text-sm"
                          :class="mgmtKey.configured ? 'text-green-500' : 'text-amber-500'"
                          :title="mgmtKey.configured ? 'Panel sign-in key configured' : 'Panel sign-in key not set'"
                        >{{ mgmtKey.configured ? 'check_circle' : 'error' }}</span>
                        <span class="material-symbols-rounded text-base text-surface-400 group-hover:text-surface-600 dark:group-hover:text-surface-200">
                          {{ showSshSetup ? 'expand_less' : 'expand_more' }}
                        </span>
                      </span>
                    </button>

                    <div v-if="showSshSetup" class="mt-3 space-y-5">
                      <!-- Group 1: connecting from the operator's own machine -->
                      <div v-if="sshAuthIsKey">
                        <p class="text-surface-700 dark:text-surface-200 text-xs font-semibold mb-1.5 flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-sm text-surface-400">computer</span>
                          Connect from your computer
                        </p>
                        <div class="flex items-center justify-between gap-2">
                          <label class="text-surface-500 dark:text-surface-400 text-xs">Private key path</label>
                          <span
                            class="text-[10px] px-1.5 py-0.5 rounded-full"
                            :class="keyPathOverride.trim() ? 'bg-amber-500/15 text-amber-500' : 'bg-surface-200 dark:bg-surface-700 text-surface-500 dark:text-surface-400'"
                          >{{ keyPathOverride.trim() ? 'Overridden for this server' : 'Using global default' }}</span>
                        </div>
                        <input
                          v-model="keyPathOverride"
                          type="text"
                          :placeholder="globalKeyPath ? globalKeyPath : 'e.g. D:\\04 Work\\ssh_keys\\vps\\vps_sftp_key'"
                          class="input w-full text-xs font-mono mt-1"
                          spellcheck="false"
                          autocomplete="off"
                        />
                        <p class="text-[11px] text-surface-500 dark:text-surface-400 mt-1">
                          <template v-if="globalKeyPath">
                            Leave empty to use the global default
                            (<span class="font-mono">{{ globalKeyPath }}</span>) from
                            <RouterLink to="/settings" class="text-primary-500 hover:underline">Settings → Fleet Access</RouterLink>.
                            Type a path to override it for this server only.
                          </template>
                          <template v-else>
                            Set a default for all servers in
                            <RouterLink to="/settings" class="text-primary-500 hover:underline">Settings → Fleet Access</RouterLink>,
                            or type a path here to use just for this server.
                          </template>
                        </p>
                        <p
                          v-if="keyPathLooksLikeDir"
                          class="text-[11px] text-amber-600 dark:text-amber-400 mt-1 flex items-start gap-1"
                        >
                          <span class="material-symbols-rounded text-sm shrink-0">warning</span>
                          <span>This must be the key <strong>file</strong>, not the folder &mdash; e.g. <span class="font-mono">{{ effectiveKeyPath }}\vps_sftp_key</span>.</span>
                        </p>
                      </div>

                      <!-- Group 2: how the Fleet Manager itself signs in -->
                      <div class="pt-4 border-t border-surface-200 dark:border-surface-700">
                        <p class="text-surface-700 dark:text-surface-200 text-xs font-semibold mb-2 flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-sm text-surface-400">hub</span>
                          How the panel signs in
                        </p>

                        <!-- The panel's PRIVATE key (+ passphrase) -->
                        <div class="flex items-center justify-between gap-2">
                          <div class="min-w-0">
                            <p class="text-surface-500 dark:text-surface-400 text-xs">Panel sign-in key</p>
                            <p class="text-[11px] mt-0.5 flex items-center gap-1" :class="mgmtKey.configured ? 'text-green-500' : 'text-amber-500'">
                              <span class="material-symbols-rounded text-sm">{{ mgmtKey.configured ? 'check_circle' : 'error' }}</span>
                              <span v-if="mgmtKey.configured" class="font-mono truncate">{{ mgmtKey.fingerprint || 'configured' }}<span v-if="mgmtKey.has_passphrase"> · passphrase set</span></span>
                              <span v-else>Not set — can't log in to a passphrase-protected box</span>
                            </p>
                          </div>
                          <button @click="showMgmtKeyForm = !showMgmtKeyForm" class="btn btn-ghost btn-xs shrink-0">
                            <span class="material-symbols-rounded text-base">{{ showMgmtKeyForm ? 'expand_less' : (mgmtKey.configured ? 'edit' : 'add') }}</span>
                            {{ showMgmtKeyForm ? 'Close' : (mgmtKey.configured ? 'Replace' : 'Add') }}
                          </button>
                        </div>

                        <div v-if="showMgmtKeyForm" class="mt-2 space-y-2">
                          <textarea
                            v-model="mgmtPrivateKey"
                            rows="5"
                            spellcheck="false"
                            placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;...paste the PRIVATE key that matches the authorized key below...&#10;-----END OPENSSH PRIVATE KEY-----"
                            class="w-full text-xs font-mono rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-900/50 text-surface-900 dark:text-surface-100 px-2.5 py-2 focus:outline-none focus:ring-1 focus:ring-green-500 resize-y"
                          ></textarea>
                          <div>
                            <label class="text-surface-500 dark:text-surface-400 text-xs">Passphrase <span class="text-surface-400">(if the key has one)</span></label>
                            <input
                              v-model="mgmtPassphrase"
                              type="password"
                              autocomplete="new-password"
                              placeholder="key passphrase"
                              class="input w-full text-sm font-mono mt-1"
                            />
                          </div>
                          <div class="flex items-center gap-2">
                            <button @click="saveMgmtKey" :disabled="savingMgmtKey" class="btn btn-primary btn-sm">
                              <span v-if="savingMgmtKey" class="spinner w-4 h-4"></span>
                              <span v-else class="material-symbols-rounded text-base">vpn_key</span>
                              Save &amp; test
                            </button>
                            <button @click="showMgmtKeyForm = false" :disabled="savingMgmtKey" class="btn btn-ghost btn-sm">Cancel</button>
                          </div>
                          <p class="text-[11px] text-surface-500 dark:text-surface-400">
                            Stored encrypted and used for every server — the private half of the authorized key below.
                          </p>
                        </div>

                        <!-- The PUBLIC key authorized on the box -->
                        <div class="mt-3">
                          <p class="text-surface-500 dark:text-surface-400 text-xs mb-1">Authorized key on this server (pxr)</p>
                          <textarea
                            v-model="authorizedKeyInput"
                            rows="3"
                            spellcheck="false"
                            placeholder="ssh-ed25519 AAAA... your-comment   (leave empty to use the fleet default key)"
                            class="w-full text-xs font-mono rounded-lg border border-surface-300 dark:border-surface-600 bg-surface-50 dark:bg-surface-900/50 text-surface-900 dark:text-surface-100 px-2.5 py-2 focus:outline-none focus:ring-1 focus:ring-green-500 resize-y"
                          ></textarea>
                          <div class="flex items-center justify-between mt-1.5 gap-2">
                            <p class="text-[11px] text-surface-500 dark:text-surface-400 leading-snug flex-1">
                              Public key allowed in as pxr. Leave empty for the fleet default.
                            </p>
                            <button
                              @click="saveAuthorizedKey"
                              :disabled="savingAuthorizedKey"
                              class="btn btn-secondary btn-sm shrink-0"
                            >
                              <span v-if="savingAuthorizedKey" class="spinner w-4 h-4"></span>
                              <span v-else class="material-symbols-rounded">key</span>
                              {{ savingAuthorizedKey ? 'Saving...' : 'Save' }}
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </template>
              </div>
            </div>
          </div>

          <!-- Versions Row -->
          <div class="card">
            <div class="card-header flex items-center justify-between">
              <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-blue-500">deployed_code</span>
                {{ isDocker ? 'Deployed Image' : 'Deployed Versions' }}
              </h2>
              <span
                v-if="isDocker"
                class="inline-flex items-center gap-1 text-xs font-mono px-2 py-0.5 rounded-md bg-blue-500/10 text-blue-600 dark:text-blue-400"
                title="Docker image tag deployed to the app tier"
              >
                <span class="material-symbols-rounded text-sm">inventory_2</span>
                {{ server.deployed_image_tag }}
              </span>
            </div>

            <!-- Docker: app tier runs one image tag; infra images are pinned upstream -->
            <div v-if="isDocker" class="card-body">
              <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div
                  v-for="app in ['Web', 'Collab', 'MailSync', 'Mail']"
                  :key="app"
                  class="text-center p-3 bg-surface-100 dark:bg-surface-700/50 rounded-xl"
                >
                  <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider mb-1">{{ app }}</p>
                  <p class="text-surface-900 dark:text-surface-100 font-mono text-sm font-semibold truncate" :title="server.deployed_image_tag">
                    {{ server.deployed_image_tag }}
                  </p>
                </div>
              </div>
              <p class="text-xs text-surface-500 dark:text-surface-400 mt-3 flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">info</span>
                MariaDB, Redis &amp; Meilisearch run pinned upstream images. Roll the app tier via Deploy &rarr; Docker Update.
              </p>
            </div>

            <!-- Native: panel / email app / agent build versions -->
            <div v-else class="card-body">
              <div class="grid grid-cols-3 gap-4">
                <div class="text-center p-3 bg-surface-100 dark:bg-surface-700/50 rounded-xl">
                  <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider mb-1">Panel</p>
                  <p class="text-surface-900 dark:text-surface-100 font-semibold">{{ server.panel_version || 'Not deployed' }}</p>
                </div>
                <div class="text-center p-3 bg-surface-100 dark:bg-surface-700/50 rounded-xl">
                  <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider mb-1">Email App</p>
                  <p class="text-surface-900 dark:text-surface-100 font-semibold">{{ server.email_app_version || 'Not deployed' }}</p>
                </div>
                <div class="text-center p-3 bg-surface-100 dark:bg-surface-700/50 rounded-xl">
                  <p class="text-surface-500 dark:text-surface-400 text-xs uppercase tracking-wider mb-1">Agent</p>
                  <p class="text-surface-900 dark:text-surface-100 font-semibold">{{ server.agent_version || 'Not deployed' }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Server Credentials -->
          <div class="card">
            <div class="card-header flex items-center justify-between gap-3 flex-wrap">
              <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-amber-500">key</span>
                Server Credentials
                <span v-if="credentials.length" class="text-xs font-medium px-2 py-0.5 rounded-full bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400">
                  {{ credentials.length }}
                </span>
              </h2>
              <div class="flex items-center gap-1.5">
                <button
                  v-if="credentials.length"
                  @click="setAllGroups(!allGroupsOpen)"
                  class="btn btn-ghost btn-xs"
                  :title="allGroupsOpen ? 'Collapse all sections' : 'Expand all sections'"
                >
                  <span class="material-symbols-rounded text-base">{{ allGroupsOpen ? 'unfold_less' : 'unfold_more' }}</span>
                  {{ allGroupsOpen ? 'Collapse all' : 'Expand all' }}
                </button>
                <button
                  v-if="credentials.some(c => c.is_secret)"
                  @click="toggleAllSecrets"
                  class="btn btn-ghost btn-xs"
                  :title="showAllSecrets ? 'Hide all secrets' : 'Reveal all secrets'"
                >
                  <span class="material-symbols-rounded text-base">{{ showAllSecrets ? 'visibility_off' : 'visibility' }}</span>
                  {{ showAllSecrets ? 'Hide all' : 'Show all' }}
                </button>
                <button @click="fetchCredentials" class="btn btn-ghost btn-xs" title="Refresh">
                  <span class="material-symbols-rounded">refresh</span>
                </button>
              </div>
            </div>

            <!-- Search -->
            <div v-if="credentials.length" class="px-4 pt-3">
              <div class="relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-lg pointer-events-none">search</span>
                <input
                  v-model="credentialSearch"
                  type="text"
                  placeholder="Search credentials (e.g. password, dkim, db user)…"
                  class="input w-full pl-10 pr-9 text-sm"
                />
                <button
                  v-if="credentialSearch"
                  @click="credentialSearch = ''"
                  class="absolute right-2 top-1/2 -translate-y-1/2 p-1 rounded hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400"
                  title="Clear"
                >
                  <span class="material-symbols-rounded text-base">close</span>
                </button>
              </div>
            </div>

            <div class="card-body">
              <div v-if="loadingCredentials" class="p-6 text-center">
                <div class="spinner w-6 h-6 mx-auto"></div>
              </div>
              <div v-else-if="credentials.length === 0" class="p-6 text-center text-surface-500 dark:text-surface-400">
                <span class="material-symbols-rounded text-3xl mb-2 block">lock</span>
                No credentials stored yet. Deploy the server to generate credentials.
              </div>
              <div v-else-if="filteredCredentialCount === 0" class="p-6 text-center text-surface-500 dark:text-surface-400">
                <span class="material-symbols-rounded text-3xl mb-2 block">search_off</span>
                No credentials match "<span class="font-medium text-surface-700 dark:text-surface-200">{{ credentialSearch }}</span>"
              </div>
              <div v-else class="space-y-4">
                <div
                  v-for="group in credentialGroups"
                  :key="group.key"
                  class="rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden"
                >
                  <!-- Category header (click to collapse/expand) -->
                  <button
                    type="button"
                    @click="toggleGroup(group.key)"
                    :class="[
                      'w-full flex items-center gap-3 px-3 py-2.5 text-left bg-surface-50 dark:bg-surface-800/40 hover:bg-surface-100 dark:hover:bg-surface-700/50 transition-colors',
                      effectiveOpen(group.key) ? 'border-b border-surface-200 dark:border-surface-700' : ''
                    ]"
                  >
                    <div :class="['w-8 h-8 rounded-lg flex items-center justify-center shrink-0', group.chip]">
                      <span class="material-symbols-rounded text-lg">{{ group.icon }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                      <p class="text-sm font-semibold text-surface-900 dark:text-surface-100 leading-tight">{{ group.label }}</p>
                      <p v-if="group.desc" class="text-xs text-surface-500 dark:text-surface-400 truncate">{{ group.desc }}</p>
                    </div>
                    <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-surface-200/70 dark:bg-surface-700 text-surface-500 dark:text-surface-400 shrink-0">
                      {{ group.items.length }}
                    </span>
                    <span
                      class="material-symbols-rounded text-surface-400 shrink-0 transition-transform duration-200"
                      :class="effectiveOpen(group.key) ? 'rotate-180' : ''"
                    >expand_more</span>
                  </button>

                  <!-- Items -->
                  <div v-show="effectiveOpen(group.key)" class="divide-y divide-surface-100 dark:divide-surface-700/50">
                    <div
                      v-for="cred in group.items"
                      :key="cred.key"
                      class="px-3 py-2.5 flex items-center gap-3 hover:bg-surface-50 dark:hover:bg-surface-700/30 transition-colors"
                    >
                      <div class="min-w-0 flex-1">
                        <p class="text-xs text-surface-500 dark:text-surface-400 mb-0.5">{{ cred.label }}</p>
                        <p
                          class="font-mono text-sm text-surface-900 dark:text-surface-100 truncate"
                          :title="isRevealed(cred) ? cred.value : ''"
                        >
                          {{ isRevealed(cred) ? cred.value : maskValue(cred.value) }}
                        </p>
                      </div>
                      <div class="flex items-center gap-0.5 shrink-0">
                        <button
                          v-if="cred.is_secret"
                          @click="toggleSecret(cred.key)"
                          class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 transition-colors"
                          :title="isRevealed(cred) ? 'Hide' : 'Show'"
                        >
                          <span class="material-symbols-rounded text-lg">
                            {{ isRevealed(cred) ? 'visibility_off' : 'visibility' }}
                          </span>
                        </button>
                        <button
                          @click="copyCredential(cred.value, cred.label, cred.key)"
                          class="p-1.5 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
                          :class="copiedKey === cred.key ? 'text-green-500' : 'text-surface-400 hover:text-surface-600 dark:hover:text-surface-200'"
                          :title="copiedKey === cred.key ? 'Copied!' : 'Copy'"
                        >
                          <span class="material-symbols-rounded text-lg">{{ copiedKey === cred.key ? 'check' : 'content_copy' }}</span>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Pending OS/npm updates (apply remotely, services auto-restart, never reboots) -->
          <UpdatesPanel :server-id="route.params.id" />

          <!-- Deployment History with Logs -->
          <div class="card">
            <div class="card-header flex items-center justify-between">
              <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-amber-500">history</span>
                Deployment History
              </h2>
              <div class="flex items-center gap-1.5">
                <button
                  @click="fetchServerLog"
                  class="btn btn-ghost btn-xs"
                  :disabled="fetchingServerLog"
                  title="Pull the provisioning log written onto the server (/var/log/fleet/)"
                >
                  <span v-if="fetchingServerLog" class="spinner-sm"></span>
                  <span v-else class="material-symbols-rounded text-base">cloud_download</span>
                  Server log
                </button>
                <button @click="fetchDeployments" class="btn btn-ghost btn-xs" title="Refresh">
                  <span class="material-symbols-rounded">refresh</span>
                </button>
              </div>
            </div>
            <div class="card-body p-0">
              <div v-if="loadingDeployments" class="p-6 text-center">
                <div class="spinner w-6 h-6 mx-auto"></div>
              </div>
              <div v-else-if="deployments.length === 0" class="p-6 text-center text-surface-500 dark:text-surface-400">
                No deployments yet
              </div>
              <div v-else class="divide-y divide-surface-200 dark:divide-surface-700">
                <div 
                  v-for="deployment in deployments" 
                  :key="deployment.id" 
                  class="p-4 flex items-center justify-between hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
                >
                  <div class="flex items-center gap-3 min-w-0 flex-1">
                    <span :class="[
                      'material-symbols-rounded text-xl',
                      deployment.status === 'success' ? 'text-green-500' :
                      deployment.status === 'failed' ? 'text-red-500' :
                      deployment.status === 'running' ? 'text-amber-500' : 'text-surface-400'
                    ]">
                      {{ deployment.status === 'success' ? 'check_circle' : 
                         deployment.status === 'failed' ? 'error' : 
                         deployment.status === 'running' ? 'sync' : 'schedule' }}
                    </span>
                    <div class="min-w-0">
                      <div class="flex items-center gap-2">
                        <p class="font-medium text-surface-900 dark:text-surface-100 truncate">
                          {{ getDeploymentTypeLabel(deployment.type) }}
                        </p>
                        <span
                          v-if="deployment.preflight_at"
                          class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[10px] font-medium"
                          :class="deployment.preflight_results?.summary?.can_proceed
                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                            : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'"
                          :title="'Preflight: ' + (deployment.preflight_results?.summary?.passed || 0) + ' passed, ' + (deployment.preflight_results?.summary?.warnings || 0) + ' warn, ' + (deployment.preflight_results?.summary?.failed || 0) + ' fail'"
                        >
                          <span class="material-symbols-rounded text-[12px]">flight_takeoff</span>
                          {{ deployment.preflight_results?.summary?.can_proceed ? 'OK' : 'FAIL' }}
                        </span>
                      </div>
                      <p class="text-xs text-surface-500 dark:text-surface-400">
                        {{ formatDate(deployment.started_at || deployment.created_at) }}
                        <span v-if="formatDeploymentDuration(deployment)" class="ml-2 inline-flex items-center gap-0.5">
                          <span class="material-symbols-rounded text-[13px] align-middle">schedule</span>
                          {{ formatDeploymentDuration(deployment) }}
                        </span>
                        <span v-if="deployment.current_step" class="ml-2 text-amber-500">
                          {{ deployment.current_step }}
                        </span>
                      </p>
                    </div>
                  </div>
                  <div class="flex items-center gap-2">
                    <span :class="['badge', getDeploymentStatusClass(deployment.status)]">
                      {{ deployment.status }}
                    </span>
                    <button
                      v-if="['running', 'pending', 'failed'].includes(deployment.status)"
                      @click="openDeployProgress(deployment)"
                      class="btn btn-ghost btn-xs"
                      title="View Progress"
                    >
                      <span class="material-symbols-rounded">timeline</span>
                    </button>
                    <button 
                      @click="viewLog(deployment)"
                      class="btn btn-ghost btn-xs"
                      title="View Log"
                    >
                      <span class="material-symbols-rounded">description</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Recent Errors -->
          <div class="card">
            <div class="card-header">
              <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-red-500">error</span>
                Recent Errors
              </h2>
            </div>
            <div class="card-body p-0">
              <div v-if="!server.recent_errors || server.recent_errors.length === 0" class="p-6 text-center text-surface-500 dark:text-surface-400">
                No errors
              </div>
              <div v-else class="divide-y divide-surface-200 dark:divide-surface-700">
                <div v-for="error in server.recent_errors" :key="error.id" class="p-4">
                  <div class="flex items-start gap-3">
                    <span :class="[
                      'material-symbols-rounded',
                      error.severity === 'critical' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'
                    ]">
                      {{ error.severity === 'critical' ? 'error' : 'warning' }}
                    </span>
                    <div class="flex-1">
                      <p class="text-sm">{{ error.message }}</p>
                      <p class="text-xs text-surface-500 dark:text-surface-400 mt-1">
                        {{ error.source }} - {{ formatDate(error.last_seen) }}
                        <span v-if="error.occurrence_count > 1" class="ml-2">
                          ({{ error.occurrence_count }} times)
                        </span>
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Sidebar (1 column) - Stats & Services -->
        <div class="space-y-6">
          <!-- Quick Stats -->
          <div id="server-health" class="card scroll-mt-24">
            <div class="card-header">
              <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-cyan-500">monitoring</span>
                Health
              </h2>
            </div>
            <div class="card-body space-y-4">
              <div v-if="server.os_info" class="flex items-center justify-between pb-3 border-b border-surface-200 dark:border-surface-700">
                <span class="text-sm text-surface-500 dark:text-surface-400 flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-base text-green-500">terminal</span>
                  Operating System
                </span>
                <span class="text-sm font-medium text-green-500">{{ server.os_info }}</span>
              </div>
              <div>
                <div class="flex items-center justify-between mb-1">
                  <span class="text-sm text-surface-500 dark:text-surface-400">CPU Load</span>
                  <span class="font-mono font-semibold text-surface-900 dark:text-surface-100">
                    {{ formatLoad(server.health?.cpu_load_1m, 2) }}
                  </span>
                </div>
              </div>
              <div>
                <div class="flex items-center justify-between mb-1">
                  <span class="text-sm text-surface-500 dark:text-surface-400">Memory</span>
                  <span class="font-mono font-semibold text-surface-900 dark:text-surface-100">
                    {{ server.health?.memory_percent || '-' }}%
                  </span>
                </div>
                <div class="h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                  <div 
                    class="h-full rounded-full transition-all"
                    :class="(server.health?.memory_percent || 0) > 90 ? 'bg-red-500' : (server.health?.memory_percent || 0) > 70 ? 'bg-amber-500' : 'bg-green-500'"
                    :style="{ width: (server.health?.memory_percent || 0) + '%' }"
                  ></div>
                </div>
              </div>
              <div>
                <div class="flex items-center justify-between mb-1">
                  <span class="text-sm text-surface-500 dark:text-surface-400">Disk</span>
                  <span class="font-mono font-semibold text-surface-900 dark:text-surface-100">
                    {{ server.health?.disk_percent || '-' }}%
                  </span>
                </div>
                <div class="h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                  <div 
                    class="h-full rounded-full transition-all"
                    :class="(server.health?.disk_percent || 0) > 90 ? 'bg-red-500' : (server.health?.disk_percent || 0) > 70 ? 'bg-amber-500' : 'bg-green-500'"
                    :style="{ width: (server.health?.disk_percent || 0) + '%' }"
                  ></div>
                </div>
              </div>
              <div class="pt-2 border-t border-surface-200 dark:border-surface-700">
                <p class="text-xs text-surface-500 dark:text-surface-400">Last Heartbeat</p>
                <p class="text-sm text-surface-900 dark:text-surface-100">{{ formatDate(server.last_heartbeat) }}</p>
              </div>
            </div>
          </div>

          <!-- DNS Records (live from the box's panel DB) -->
          <div class="card">
            <div class="card-header flex items-center justify-between">
              <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-emerald-500">dns</span>
                DNS Records
                <span v-if="dnsRecords.length" class="text-xs font-normal text-surface-500 dark:text-surface-400">({{ dnsRecords.length }})</span>
              </h2>
              <div v-if="!isDocker" class="flex items-center gap-1.5">
                <button
                  @click="reseedDns"
                  :disabled="reseedingDns"
                  class="btn btn-secondary btn-xs"
                  title="Add any missing SPF / DMARC / MX / DKIM records (idempotent - existing rows are kept)"
                >
                  <span v-if="reseedingDns" class="spinner w-3.5 h-3.5"></span>
                  <span v-else class="material-symbols-rounded text-sm">restart_alt</span>
                  {{ reseedingDns ? 'Re-seeding...' : 'Re-seed' }}
                </button>
                <button @click="fetchDns" class="btn btn-ghost btn-xs" :disabled="loadingDns" title="Reload live from the server">
                  <span class="material-symbols-rounded" :class="{ 'animate-spin': loadingDns }">refresh</span>
                </button>
              </div>
            </div>
            <div class="card-body">
              <!-- Docker boxes don't host DNS (no PowerDNS in the compose stack) -->
              <div v-if="isDocker" class="text-sm text-surface-600 dark:text-surface-400 space-y-2">
                <p class="flex items-start gap-2">
                  <span class="material-symbols-rounded text-base text-teal-500 shrink-0">info</span>
                  <span>This server runs the Docker stack, which doesn't host its own DNS. Publish DNS at your domain registrar (or wherever the zone is delegated).</span>
                </p>
                <p class="text-xs">
                  The exact <span class="font-medium">MX / SPF / DMARC / DKIM</span> records to publish are listed above under
                  <span class="font-medium">Server Credentials → DNS</span>.
                </p>
              </div>
              <div v-else-if="loadingDns && !dnsRecords.length" class="text-center py-6">
                <div class="spinner w-6 h-6 mx-auto mb-2"></div>
                <p class="text-sm text-surface-500 dark:text-surface-400">Reading DNS zone from the server...</p>
              </div>
              <div v-else-if="dnsRecords.length">
                <p v-if="dnsDb" class="text-xs text-surface-500 dark:text-surface-400 mb-2">
                  Live from <span class="font-mono">{{ dnsDb }}</span> on the server
                </p>
                <div class="space-y-1.5 max-h-80 overflow-y-auto pr-1">
                  <div
                    v-for="(rec, i) in dnsRecords"
                    :key="i"
                    class="flex items-start gap-2 py-2 border-b border-surface-100 dark:border-surface-700/50 last:border-0"
                  >
                    <span :class="['inline-flex shrink-0 items-center justify-center px-1.5 py-0.5 rounded text-[11px] font-semibold min-w-[3rem]', dnsTypeClass(rec.type)]">
                      {{ rec.type }}<template v-if="rec.type === 'MX' && rec.prio"> {{ rec.prio }}</template>
                    </span>
                    <div class="min-w-0 flex-1">
                      <p class="text-xs font-medium text-surface-900 dark:text-surface-100 break-all">{{ rec.name }}</p>
                      <p class="text-[11px] text-surface-600 dark:text-surface-400 break-all font-mono leading-snug">{{ rec.content }}</p>
                    </div>
                    <button
                      @click="copyDnsValue(rec.content)"
                      class="p-1 rounded-lg hover:bg-surface-200 dark:hover:bg-surface-600 text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 shrink-0"
                      title="Copy value"
                    >
                      <span class="material-symbols-rounded text-base">content_copy</span>
                    </button>
                  </div>
                </div>
              </div>
              <div v-else class="text-center py-6 text-sm text-surface-500 dark:text-surface-400 space-y-2">
                <p>No DNS records found, or the server isn't reachable yet.</p>
                <p class="text-xs">Run Test Connection first, then refresh. Use "Re-seed" to (re)create the SPF / DMARC / MX / DKIM records.</p>
              </div>
            </div>
          </div>

          <!-- CPGuard (per-server, IP-bound license) -->
          <div class="card">
            <div class="card-header flex items-center justify-between">
              <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-orange-500">gpp_good</span>
                CPGuard
                <span
                  v-if="cpguard?.installed"
                  class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-semibold bg-green-500/10 text-green-500"
                >Installed</span>
                <span
                  v-else-if="cpguard?.reachable && cpguard?.installed === false"
                  class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-semibold bg-surface-500/10 text-surface-400"
                >Not installed</span>
              </h2>
              <button @click="fetchCpguard" class="btn btn-ghost btn-xs" :disabled="loadingCpguard" title="Re-check live on the server">
                <span class="material-symbols-rounded" :class="{ 'animate-spin': loadingCpguard }">refresh</span>
              </button>
            </div>
            <div class="card-body space-y-3">
              <div v-if="loadingCpguard && !cpguard" class="text-center py-4">
                <div class="spinner w-6 h-6 mx-auto mb-2"></div>
                <p class="text-sm text-surface-500 dark:text-surface-400">Checking CPGuard on the server...</p>
              </div>

              <div v-else-if="cpguard && cpguard.reachable === false" class="text-sm text-surface-500 dark:text-surface-400">
                <p>Server unreachable over SSH - run Test Connection first, then refresh.</p>
                <p v-if="cpguard.has_license" class="text-xs mt-1.5 flex items-center gap-1 text-green-600 dark:text-green-400">
                  <span class="material-symbols-rounded text-sm">key</span>
                  A license key is on file - CPGuard installs automatically on the next deploy.
                </p>
              </div>

              <template v-else-if="cpguard">
                <div v-if="cpguard.installed" class="space-y-2">
                  <div class="flex items-center justify-between py-1">
                    <span class="text-sm text-surface-700 dark:text-surface-300">Service</span>
                    <span :class="['text-sm font-medium', cpguard.service === 'active' ? 'text-green-500' : 'text-amber-500']">
                      {{ cpguard.service === 'active' ? 'Running' : 'Stopped' }}
                    </span>
                  </div>
                  <div class="flex items-center justify-between py-1">
                    <span class="text-sm text-surface-700 dark:text-surface-300">License file</span>
                    <span :class="['text-sm font-medium', cpguard.license_file ? 'text-green-500' : 'text-red-500']">
                      {{ cpguard.license_file ? 'Present' : 'Missing' }}
                    </span>
                  </div>
                  <p class="text-xs text-surface-500 dark:text-surface-400 pt-1 border-t border-surface-200 dark:border-surface-700">
                    Manage scanning, WAF and lists from this server's panel (Security view).
                  </p>
                </div>

                <div v-else class="space-y-3">
                  <p class="text-sm text-surface-500 dark:text-surface-400">
                    CPGuard is not installed. Licenses are bound to the server IP -
                    enter the key bought for <span class="font-mono text-surface-700 dark:text-surface-300">{{ server.ip_address }}</span>.
                  </p>
                  <input
                    v-model="cpguardKeyInput"
                    type="text"
                    class="input w-full"
                    :placeholder="cpguard.has_license ? 'Key on file - leave empty to use it' : 'CPGuard license key'"
                    autocomplete="off"
                  />
                  <p v-if="cpguard.has_license" class="text-xs flex items-center gap-1 text-green-600 dark:text-green-400">
                    <span class="material-symbols-rounded text-sm">key</span>
                    A license key is already on file for this server.
                  </p>
                  <button
                    @click="installCpguard"
                    :disabled="installingCpguard"
                    class="btn btn-primary btn-sm w-full"
                  >
                    <span v-if="installingCpguard" class="spinner w-4 h-4"></span>
                    <span v-else class="material-symbols-rounded text-base">download</span>
                    {{ installingCpguard ? 'Installing... (can take a few minutes)' : 'Install CPGuard' }}
                  </button>
                </div>
              </template>

              <p v-else class="text-sm text-surface-500 dark:text-surface-400 text-center py-2">
                CPGuard state unknown - refresh to check.
              </p>
            </div>
          </div>

          <!-- Services -->
          <div class="card">
            <div class="card-header flex items-center justify-between">
              <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-purple-500">settings_suggest</span>
                {{ isDocker ? 'Containers' : 'Services' }}
                <span
                  v-if="isDocker && dockerStatus"
                  :class="['inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-semibold',
                           dockerStatus.healthy ? 'bg-green-500/10 text-green-500' : 'bg-amber-500/10 text-amber-500']"
                >{{ dockerStatus.healthy ? 'Healthy' : (dockerStatus.reachable ? 'Degraded' : 'Unreachable') }}</span>
              </h2>
              <button
                v-if="isDocker"
                @click="fetchDockerStatus"
                class="btn btn-ghost btn-xs"
                :disabled="loadingDockerStatus"
                title="Re-check live `docker compose ps` on the server"
              >
                <span class="material-symbols-rounded" :class="{ 'animate-spin': loadingDockerStatus }">refresh</span>
              </button>
            </div>

            <!-- Docker: live container state from `docker compose ps` -->
            <div v-if="isDocker" class="card-body">
              <div v-if="loadingDockerStatus && !dockerStatus" class="text-center py-4">
                <div class="spinner w-6 h-6 mx-auto mb-2"></div>
                <p class="text-sm text-surface-500 dark:text-surface-400">Reading container status...</p>
              </div>
              <div v-else-if="dockerStatus && dockerStatus.reachable === false" class="text-sm text-surface-500 dark:text-surface-400 py-2">
                Server unreachable over SSH - run Test Connection first, then refresh.
              </div>
              <div v-else-if="dockerServiceList.length" class="space-y-2">
                <div v-for="svc in dockerServiceList" :key="svc.key" class="flex items-center justify-between py-1">
                  <span class="text-sm text-surface-700 dark:text-surface-300 flex items-center gap-1.5">
                    {{ svc.label }}
                    <span v-if="!svc.app" class="text-[10px] uppercase tracking-wider text-surface-400 dark:text-surface-500">infra</span>
                  </span>
                  <span class="flex items-center gap-1.5">
                    <span v-if="svc.health && svc.health !== 'healthy'" class="text-[10px] uppercase text-surface-400 dark:text-surface-500">{{ svc.health }}</span>
                    <span :class="['material-symbols-rounded text-lg', getServiceStatusClass(svc.status)]">
                      {{ svc.status === 'running' ? 'check_circle' : svc.status === 'disabled' ? 'pending' : svc.status === 'error' ? 'error' : 'cancel' }}
                    </span>
                  </span>
                </div>
              </div>
              <p v-else class="text-surface-500 dark:text-surface-400 text-center py-4 text-sm">
                No container data - refresh once the stack is up.
              </p>
            </div>

            <!-- Native: systemd services from the agent heartbeat / audit -->
            <div v-else class="card-body">
              <div v-if="server.health || auditData" class="space-y-2">
                <div v-for="svc in serviceList" :key="svc.label" class="flex items-center justify-between py-1">
                  <span class="text-sm text-surface-700 dark:text-surface-300">{{ svc.label }}</span>
                  <span :class="['material-symbols-rounded text-lg', getServiceStatusClass(svc.status)]">
                    {{ svc.status === 'running' ? 'check_circle' : svc.status === 'disabled' ? 'block' : svc.status === 'error' ? 'error' : 'cancel' }}
                  </span>
                </div>
              </div>
              <p v-else class="text-surface-500 dark:text-surface-400 text-center py-4 text-sm">
                No health data available
              </p>
            </div>
          </div>

          <!-- Deployment Audit -->
          <div class="card">
            <div class="card-header flex items-center justify-between">
              <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-indigo-500">verified</span>
                Deployment Audit
              </h2>
              <div class="flex items-center gap-2">
                <button @click="runAudit" :disabled="runningAudit" class="btn btn-secondary btn-xs" title="Run live audit">
                  <span v-if="runningAudit" class="spinner w-3.5 h-3.5"></span>
                  <span v-else class="material-symbols-rounded text-sm">play_arrow</span>
                  {{ runningAudit ? 'Auditing...' : 'Run Audit' }}
                </button>
                <button @click="fetchAudit" class="btn btn-ghost btn-xs" title="Refresh cached data">
                  <span class="material-symbols-rounded">refresh</span>
                </button>
              </div>
            </div>
            <div class="card-body">
              <div v-if="loadingAudit || runningAudit" class="text-center py-6">
                <div class="spinner w-6 h-6 mx-auto mb-2"></div>
                <p class="text-sm text-surface-500 dark:text-surface-400">
                  {{ runningAudit ? 'Running integrity checks via SSH...' : 'Loading...' }}
                </p>
              </div>
              <div v-else-if="!auditData" class="text-center py-6 text-surface-500 dark:text-surface-400 text-sm space-y-2">
                <span class="material-symbols-rounded text-3xl block">verified</span>
                <p>No audit data yet</p>
                <button @click="runAudit" class="btn btn-primary btn-sm mx-auto">
                  <span class="material-symbols-rounded text-sm">play_arrow</span>
                  Run First Audit
                </button>
              </div>
              <div v-else>
                <!-- Summary bar -->
                <div class="flex items-center gap-3 p-2.5 rounded-xl mb-3" :class="[
                  auditData.failed > 0 
                    ? 'bg-red-500/10 border border-red-500/20' 
                    : auditData.warnings > 0 
                      ? 'bg-amber-500/10 border border-amber-500/20'
                      : 'bg-green-500/10 border border-green-500/20'
                ]">
                  <span class="material-symbols-rounded text-xl" :class="[
                    auditData.failed > 0 ? 'text-red-500' : auditData.warnings > 0 ? 'text-amber-500' : 'text-green-500'
                  ]">
                    {{ auditData.failed > 0 ? 'gpp_bad' : auditData.warnings > 0 ? 'gpp_maybe' : 'verified_user' }}
                  </span>
                  <div class="flex-1">
                    <div class="flex items-center gap-2">
                      <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-green-500/10 text-green-500">
                        <span class="material-symbols-rounded text-xs">check</span>
                        {{ auditData.passed }}
                      </span>
                      <span v-if="auditData.failed > 0" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-red-500/10 text-red-500">
                        <span class="material-symbols-rounded text-xs">close</span>
                        {{ auditData.failed }}
                      </span>
                      <span v-if="auditData.warnings > 0" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-500">
                        <span class="material-symbols-rounded text-xs">warning</span>
                        {{ auditData.warnings }}
                      </span>
                    </div>
                  </div>
                  <button 
                    v-if="hasFixableIssues"
                    @click="fixAllAuditIssues"
                    :disabled="fixingAll"
                    class="btn btn-primary btn-xs shrink-0"
                  >
                    <span v-if="fixingAll" class="spinner w-3.5 h-3.5"></span>
                    <span v-else class="material-symbols-rounded text-sm">build</span>
                    {{ fixingAll ? 'Fixing...' : 'Fix All' }}
                  </button>
                </div>

                <!-- Checks by category -->
                <div class="space-y-3">
                  <div v-for="(group, catKey) in auditChecksByCategory" :key="catKey">
                    <p class="text-xs font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400 mb-1">
                      {{ group.label }}
                    </p>
                    <div class="bg-surface-50 dark:bg-surface-700/30 rounded-xl overflow-hidden divide-y divide-surface-200 dark:divide-surface-700">
                      <div 
                        v-for="(check, idx) in group.items" 
                        :key="idx"
                        class="flex items-center gap-2 px-3 py-1.5"
                      >
                        <span :class="['material-symbols-rounded text-base', auditStatusColor(check.status)]">
                          {{ auditStatusIcon(check.status) }}
                        </span>
                        <span class="text-xs text-surface-700 dark:text-surface-300 flex-1 truncate">
                          {{ check.name }}
                        </span>
                        <span v-if="check.detail && check.status !== 'pass'" class="text-[10px] text-surface-500 dark:text-surface-400 truncate max-w-[100px]" :title="check.detail">
                          {{ check.detail }}
                        </span>
                        <button
                          v-if="check.fix_action && check.status !== 'pass'"
                          @click="fixAuditCheck(check)"
                          :disabled="fixingCheck === check.name"
                          class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-primary-500/10 text-primary-600 dark:text-primary-400 hover:bg-primary-500/20 transition-colors shrink-0"
                          :title="'Fix: ' + check.fix_action"
                        >
                          <span v-if="fixingCheck === check.name" class="spinner w-3 h-3"></span>
                          <span v-else class="material-symbols-rounded text-xs">build</span>
                          Fix
                        </button>
                        <span :class="[
                          'px-1.5 py-0.5 rounded-full text-[9px] font-semibold uppercase shrink-0',
                          check.status === 'pass' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' :
                          check.status === 'fail' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' :
                          'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
                        ]">
                          {{ check.status }}
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="flex items-center justify-between mt-3 pt-2 border-t border-surface-200 dark:border-surface-700">
                  <p v-if="auditData.stored_at" class="text-xs text-surface-500 dark:text-surface-400">
                    Last audit: {{ formatDate(auditData.stored_at) }}
                  </p>
                  <p v-if="auditData.duration_ms" class="text-xs text-surface-500 dark:text-surface-400">
                    {{ (auditData.duration_ms / 1000).toFixed(1) }}s
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Log Viewer Modal -->
      <Teleport to="body">
        <Transition name="modal">
          <div v-if="showLogModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
            <div class="bg-surface-800 rounded-2xl w-full max-w-4xl max-h-[80vh] shadow-2xl border border-surface-700 overflow-hidden flex flex-col">
              <!-- Header -->
              <div class="bg-gradient-to-r from-surface-700 to-surface-600 p-4 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-full bg-surface-500/30 flex items-center justify-center">
                    <span class="material-symbols-rounded text-xl text-white">description</span>
                  </div>
                  <div>
                    <h3 class="font-bold text-white">Deployment Log</h3>
                    <p class="text-surface-300 text-sm">
                      {{ getDeploymentTypeLabel(selectedDeployment?.type) }} - 
                      <span :class="[
                        selectedDeployment?.status === 'success' ? 'text-green-400' :
                        selectedDeployment?.status === 'failed' ? 'text-red-400' :
                        selectedDeployment?.status === 'running' ? 'text-amber-400' : 'text-surface-400'
                      ]">{{ selectedDeployment?.status }}</span>
                    </p>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <button @click="downloadLog" class="btn btn-secondary btn-sm">
                    <span class="material-symbols-rounded">download</span>
                    Download
                  </button>
                  <button @click="showLogModal = false" class="btn btn-ghost btn-sm text-white">
                    <span class="material-symbols-rounded">close</span>
                  </button>
                </div>
              </div>
              
              <!-- Log Content -->
              <div class="flex-1 overflow-auto p-4 bg-surface-900">
                <div v-if="loadingLog" class="flex items-center justify-center py-12">
                  <div class="spinner w-8 h-8"></div>
                </div>
                <pre v-else class="text-sm text-surface-300 font-mono whitespace-pre-wrap break-words">{{ deploymentLog }}</pre>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>
    </template>

    <!-- Deployment Modal -->
    <DeploymentModal
      :show="showDeployModal"
      :server-id="server?.id"
      :server-name="server?.name"
      :current-blueprint-id="server?.blueprint_id"
      :initial-type="deployType"
      :resume-deployment-id="resumeDeployId"
      @close="showDeployModal = false; resumeDeployId = null; fetchServer()"
      @deployed="handleDeployed"
    />
  </div>
</template>

<style scoped>
/* Modal transitions */
.modal-enter-active,
.modal-leave-active {
  transition: all 0.3s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-from > div,
.modal-leave-to > div {
  transform: scale(0.9) translateY(20px);
}

.modal-enter-active > div,
.modal-leave-active > div {
  transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}
</style>
