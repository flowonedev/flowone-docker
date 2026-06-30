<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/services/api'
import { cache, CACHE_KEYS, TTL } from '@/services/cache'
import { useToastStore } from '@/stores/toast'
import StatusBadge from '@/components/StatusBadge.vue'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import Toggle from '@/components/Toggle.vue'

const toast = useToastStore()
const getCacheAge = (key) => cache.getAgeHuman(key)

const activeTab = ref('fail2ban')
const loading = ref(true)
const submitting = ref(false)

// ============================================
// Fail2ban State
// ============================================
const fail2banStatus = ref(null)
const jails = ref([])
const selectedJail = ref(null)

// Fail2ban Modals
const createJailModal = ref(false)
const editJailModal = ref({ show: false, jail: null })
const deleteJailModal = ref({ show: false, jail: null })
const banIpModal = ref({ show: false, ip: '' })
const unbanModal = ref({ show: false, ip: '' })

// New jail form
const newJail = ref({
  name: '',
  enabled: true,
  port: 'http,https',
  filter: '',
  logpath: '',
  maxretry: 5,
  bantime: '10m',
  findtime: '10m'
})

// Edit jail form
const editJail = ref({
  name: '',
  enabled: true,
  port: '',
  filter: '',
  logpath: '',
  maxretry: 5,
  bantime: '',
  findtime: ''
})

// ============================================
// Firewall State
// ============================================
const firewallStatus = ref(null)
const zones = ref([])
const selectedZone = ref(null)

// Firewall Modals
const addPortModal = ref(false)
const addServiceModal = ref(false)
const removePortModal = ref({ show: false, port: null })
const removeServiceModal = ref({ show: false, service: null })

// New port/service forms
const newPort = ref({ port: '', protocol: 'tcp' })
const newService = ref('')

// Common services list
const commonServices = [
  'http', 'https', 'ssh', 'ftp', 'smtp', 'smtps', 'imap', 'imaps', 
  'pop3', 'pop3s', 'dns', 'mysql', 'postgresql', 'redis', 'mongodb'
]

// ============================================
// ModSecurity State
// ============================================
const modsecStatus = ref(null)
const modsecRules = ref([])
const modsecAuditLog = ref([])
const modsecLoading = ref(false)

// ============================================
// CPGuard State
// ============================================
const cpguardStatus = ref(null)
const cpguardLoading = ref(false)
const cpguardLicense = ref(null)
const cpguardLists = ref({
  whitelist_ips: [],
  whitelist_domains: [],
  whitelist_files: [],
  whitelist_urls: [],
  blacklist_ips: [],
  blacklist_files: [],
  whitelist_countries: [],
  blacklist_countries: [],
  bad_bots: [],
  bf_urls: [],
  waf_urls: [],
  fw_whitelist_ips: [],
  fw_blacklist_ips: [],
  temp_bans: []
})
const cpguardConfig = ref(null)

// CPGuard Modals
const cpguardInstallModal = ref(false)
const cpguardLicenseModal = ref(false)
const cpguardAddWhitelistIpModal = ref(false)
const cpguardAddWhitelistDomainModal = ref(false)
const cpguardAddBlacklistIpModal = ref(false)
const cpguardRemoveModal = ref({ show: false, type: '', listType: '', value: '' })
const cpguardScanModal = ref(false)

// CPGuard Forms
const cpguardInstallForm = ref({ license_key: '' })
const cpguardLicenseForm = ref({ license_key: '' })
const cpguardWhitelistIpForm = ref({ ip: '' })
const cpguardWhitelistDomainForm = ref({ domain: '' })
const cpguardBlacklistIpForm = ref({ ip: '' })
const cpguardScanForm = ref({ path: '/home', background: true })

// CPGuard active sub-tab
const cpguardActiveSection = ref('status')

// ============================================
// Tabs Configuration
// ============================================
const tabs = [
  { id: 'fail2ban', label: 'Fail2ban', icon: 'block' },
  { id: 'firewall', label: 'Firewall', icon: 'local_fire_department' },
  { id: 'modsec', label: 'ModSecurity', icon: 'security' },
  { id: 'cpguard', label: 'CPGuard', icon: 'shield' },
  { id: 'dep_scan', label: 'Dependencies', icon: 'bug_report' },
]

// ============================================
// Dependency Scan State
// ============================================
const depScans = ref([])
const depTotals = ref({ vulnerabilities: 0, critical: 0, high: 0, medium: 0, low: 0 })
const depHistory = ref([])
const depLoading = ref(false)
const depExpandedScan = ref(null)

const fetchDepScans = async () => {
  depLoading.value = true
  try {
    const [latestRes, historyRes] = await Promise.all([
      api.get('/security/scans'),
      api.get('/security/scans/history', { params: { limit: 20 } })
    ])
    if (latestRes.data.success) {
      depScans.value = latestRes.data.data.scans || []
      depTotals.value = latestRes.data.data.totals || depTotals.value
    }
    if (historyRes.data.success) {
      depHistory.value = historyRes.data.data.history || []
    }
  } catch (e) {
    toast.error('Failed to load dependency scans')
  } finally {
    depLoading.value = false
  }
}

const depScanStatusClass = computed(() => {
  if (depTotals.value.critical > 0) return 'text-red-500'
  if (depTotals.value.high > 0) return 'text-orange-500'
  if (depTotals.value.medium > 0) return 'text-amber-500'
  return 'text-green-500'
})

const depScanStatusIcon = computed(() => {
  if (depTotals.value.critical > 0) return 'error'
  if (depTotals.value.high > 0) return 'warning'
  if (depTotals.value.medium > 0) return 'info'
  return 'check_circle'
})

const depScanStatusText = computed(() => {
  if (depTotals.value.critical > 0) return `${depTotals.value.critical} Critical`
  if (depTotals.value.high > 0) return `${depTotals.value.high} High`
  if (depTotals.value.medium > 0) return `${depTotals.value.medium} Medium`
  if (depTotals.value.vulnerabilities > 0) return `${depTotals.value.low} Low`
  return 'All Clear'
})

const formatScanDate = (dateStr) => {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  return d.toLocaleString('en-GB', { 
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit'
  })
}

const toggleDepScan = (id) => {
  depExpandedScan.value = depExpandedScan.value === id ? null : id
}

// ============================================
// Fail2ban Functions
// ============================================
const fetchFail2ban = async (forceRefresh = false) => {
  // Check cache first
  if (!forceRefresh) {
    const cachedStatus = cache.get(CACHE_KEYS.FAIL2BAN_STATUS)
    const cachedJails = cache.get(CACHE_KEYS.FAIL2BAN_JAILS)
    if (cachedStatus && cachedJails) {
      fail2banStatus.value = cachedStatus
      jails.value = cachedJails
      if (!selectedJail.value && jails.value.length) {
        selectedJail.value = jails.value[0]
      }
      return
    }
  }
  
  try {
    const [statusRes, jailsRes] = await Promise.all([
      api.get('/fail2ban/status'),
      api.get('/fail2ban/jails')
    ])
    
    if (statusRes.data.success) {
      fail2banStatus.value = statusRes.data.data
      cache.set(CACHE_KEYS.FAIL2BAN_STATUS, fail2banStatus.value, TTL.LONG)
    }
    if (jailsRes.data.success) {
      jails.value = jailsRes.data.data.jails || []
      cache.set(CACHE_KEYS.FAIL2BAN_JAILS, jails.value, TTL.LONG)
      // Auto-select first jail if none selected
      if (!selectedJail.value && jails.value.length) {
        selectedJail.value = jails.value[0]
      }
    }
  } catch (e) {
    toast.error('Failed to load Fail2ban data')
  }
}

const selectJail = (jail) => {
  selectedJail.value = jail
}

const createJail = async () => {
  submitting.value = true
  try {
    const response = await api.post('/fail2ban/jails', {
      name: newJail.value.name,
      enabled: newJail.value.enabled ? 'true' : 'false',
      port: newJail.value.port,
      filter: newJail.value.filter || newJail.value.name,
      logpath: newJail.value.logpath,
      maxretry: newJail.value.maxretry,
      bantime: newJail.value.bantime,
      findtime: newJail.value.findtime
    })
    
    if (response.data.success) {
      toast.success('Jail created successfully')
      createJailModal.value = false
      resetNewJail()
      await fetchFail2ban()
    } else {
      toast.error(response.data.error || 'Failed to create jail')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create jail')
  } finally {
    submitting.value = false
  }
}

const deleteJail = async () => {
  if (!deleteJailModal.value.jail) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/fail2ban/jails/${deleteJailModal.value.jail.name}`)
    
    if (response.data.success) {
      toast.success('Jail deleted successfully')
      deleteJailModal.value = { show: false, jail: null }
      if (selectedJail.value?.name === deleteJailModal.value.jail?.name) {
        selectedJail.value = null
      }
      await fetchFail2ban()
    } else {
      toast.error(response.data.error || 'Failed to delete jail')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete jail')
  } finally {
    submitting.value = false
  }
}

const openEditJail = async (jail) => {
  // Fetch fresh jail details from API
  try {
    const response = await api.get(`/fail2ban/jails/${jail.name}`)
    if (response.data.success) {
      const jailData = response.data.data.jail
      editJail.value = {
        name: jailData.name,
        enabled: jailData.enabled !== false,
        port: jailData.port || '',
        filter: jailData.filter || jailData.name,
        logpath: jailData.logpath || '',
        maxretry: parseInt(jailData.maxretry) || 5,
        bantime: jailData.bantime || '10m',
        findtime: jailData.findtime || '10m'
      }
      editJailModal.value = { show: true, jail: jailData }
    } else {
      toast.error('Failed to load jail details')
    }
  } catch (e) {
    toast.error('Failed to load jail details')
    console.error(e)
  }
}

const updateJail = async () => {
  if (!editJailModal.value.jail) return
  
  submitting.value = true
  try {
    const response = await api.put(`/fail2ban/jails/${editJailModal.value.jail.name}`, {
      enabled: editJail.value.enabled ? 'true' : 'false',
      port: editJail.value.port,
      filter: editJail.value.filter,
      logpath: editJail.value.logpath,
      maxretry: editJail.value.maxretry,
      bantime: editJail.value.bantime,
      findtime: editJail.value.findtime
    })
    
    if (response.data.success) {
      toast.success('Jail updated successfully')
      editJailModal.value = { show: false, jail: null }
      await fetchFail2ban()
    } else {
      toast.error(response.data.error || 'Failed to update jail')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update jail')
  } finally {
    submitting.value = false
  }
}

const toggleJailEnabled = async (jail) => {
  submitting.value = true
  try {
    const endpoint = jail.enabled ? 'disable' : 'enable'
    const response = await api.post(`/fail2ban/jails/${jail.name}/${endpoint}`)
    
    if (response.data.success) {
      toast.success(`Jail ${endpoint}d successfully`)
      await fetchFail2ban()
    } else {
      toast.error(response.data.error || `Failed to ${endpoint} jail`)
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to toggle jail')
  } finally {
    submitting.value = false
  }
}

const banIp = async () => {
  if (!selectedJail.value || !banIpModal.value.ip) return
  
  submitting.value = true
  try {
    const response = await api.post(`/fail2ban/jails/${selectedJail.value.name}/ban`, {
      ip: banIpModal.value.ip
    })
    
    if (response.data.success) {
      toast.success('IP banned successfully')
      banIpModal.value = { show: false, ip: '' }
      await fetchFail2ban()
      // Refresh selected jail
      const updated = jails.value.find(j => j.name === selectedJail.value.name)
      if (updated) selectedJail.value = updated
    } else {
      toast.error(response.data.error || 'Failed to ban IP')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to ban IP')
  } finally {
    submitting.value = false
  }
}

const unbanIp = async () => {
  if (!selectedJail.value || !unbanModal.value.ip) return
  
  submitting.value = true
  try {
    const response = await api.post(`/fail2ban/jails/${selectedJail.value.name}/unban`, {
      ip: unbanModal.value.ip
    })
    
    if (response.data.success) {
      toast.success('IP unbanned successfully')
      unbanModal.value = { show: false, ip: '' }
      await fetchFail2ban()
      // Refresh selected jail
      const updated = jails.value.find(j => j.name === selectedJail.value.name)
      if (updated) selectedJail.value = updated
    } else {
      toast.error(response.data.error || 'Failed to unban IP')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to unban IP')
  } finally {
    submitting.value = false
  }
}

const resetNewJail = () => {
  newJail.value = {
    name: '',
    enabled: true,
    port: 'http,https',
    filter: '',
    logpath: '',
    maxretry: 5,
    bantime: '10m',
    findtime: '10m'
  }
}

// ============================================
// Firewall Functions
// ============================================
const fetchFirewall = async (forceRefresh = false) => {
  // Check cache first
  if (!forceRefresh) {
    const cachedStatus = cache.get(CACHE_KEYS.FIREWALL_STATUS)
    const cachedZones = cache.get(CACHE_KEYS.FIREWALL_ZONES)
    if (cachedStatus && cachedZones) {
      firewallStatus.value = cachedStatus
      zones.value = cachedZones
      if (zones.value.length && !selectedZone.value) {
        selectedZone.value = zones.value.find(z => z.default) || zones.value[0]
      }
      return
    }
  }
  
  try {
    const [statusRes, zonesRes] = await Promise.all([
      api.get('/firewall/status'),
      api.get('/firewall/zones')
    ])
    
    if (statusRes.data.success) {
      firewallStatus.value = statusRes.data.data
      cache.set(CACHE_KEYS.FIREWALL_STATUS, firewallStatus.value, TTL.LONG)
    }
    if (zonesRes.data.success) {
      zones.value = zonesRes.data.data.zones || []
      cache.set(CACHE_KEYS.FIREWALL_ZONES, zones.value, TTL.LONG)
      if (zones.value.length && !selectedZone.value) {
        selectedZone.value = zones.value.find(z => z.default) || zones.value[0]
      }
    }
  } catch (e) {
    toast.error('Failed to load firewall data')
  }
}

const addPort = async () => {
  if (!newPort.value.port) return
  
  submitting.value = true
  try {
    const response = await api.post('/firewall/ports', {
      port: parseInt(newPort.value.port),
      protocol: newPort.value.protocol,
      zone: selectedZone.value?.name,
      permanent: true
    })
    
    if (response.data.success) {
      toast.success('Port added successfully')
      addPortModal.value = false
      newPort.value = { port: '', protocol: 'tcp' }
      await fetchFirewall()
      // Refresh selected zone
      const updated = zones.value.find(z => z.name === selectedZone.value?.name)
      if (updated) selectedZone.value = updated
    } else {
      toast.error(response.data.error || 'Failed to add port')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to add port')
  } finally {
    submitting.value = false
  }
}

const removePort = async () => {
  if (!removePortModal.value.port) return
  
  const [port, protocol] = removePortModal.value.port.split('/')
  
  submitting.value = true
  try {
    const response = await api.delete(`/firewall/ports/${port}/${protocol}`, {
      data: { zone: selectedZone.value?.name, permanent: true }
    })
    
    if (response.data.success) {
      toast.success('Port removed successfully')
      removePortModal.value = { show: false, port: null }
      await fetchFirewall()
      const updated = zones.value.find(z => z.name === selectedZone.value?.name)
      if (updated) selectedZone.value = updated
    } else {
      toast.error(response.data.error || 'Failed to remove port')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to remove port')
  } finally {
    submitting.value = false
  }
}

const addService = async () => {
  if (!newService.value) return
  
  submitting.value = true
  try {
    const response = await api.post('/firewall/services', {
      service: newService.value,
      zone: selectedZone.value?.name,
      permanent: true
    })
    
    if (response.data.success) {
      toast.success('Service added successfully')
      addServiceModal.value = false
      newService.value = ''
      await fetchFirewall()
      const updated = zones.value.find(z => z.name === selectedZone.value?.name)
      if (updated) selectedZone.value = updated
    } else {
      toast.error(response.data.error || 'Failed to add service')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to add service')
  } finally {
    submitting.value = false
  }
}

const removeService = async () => {
  if (!removeServiceModal.value.service) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/firewall/services/${removeServiceModal.value.service}`, {
      data: { zone: selectedZone.value?.name, permanent: true }
    })
    
    if (response.data.success) {
      toast.success('Service removed successfully')
      removeServiceModal.value = { show: false, service: null }
      await fetchFirewall()
      const updated = zones.value.find(z => z.name === selectedZone.value?.name)
      if (updated) selectedZone.value = updated
    } else {
      toast.error(response.data.error || 'Failed to remove service')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to remove service')
  } finally {
    submitting.value = false
  }
}

const reloadFirewall = async () => {
  submitting.value = true
  try {
    const response = await api.post('/firewall/reload')
    
    if (response.data.success) {
      toast.success('Firewall reloaded successfully')
      await fetchFirewall()
    } else {
      toast.error(response.data.error || 'Failed to reload firewall')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to reload firewall')
  } finally {
    submitting.value = false
  }
}

// Available services (not already added)
const availableServices = computed(() => {
  const current = selectedZone.value?.services || []
  return commonServices.filter(s => !current.includes(s))
})

// ============================================
// ModSecurity Functions
// ============================================
const fetchModsec = async () => {
  modsecLoading.value = true
  try {
    const [statusRes, rulesRes, logRes] = await Promise.all([
      api.get('/modsec/status'),
      api.get('/modsec/rules'),
      api.get('/modsec/audit-log', { params: { limit: 50 } })
    ])
    
    if (statusRes.data.success) {
      modsecStatus.value = statusRes.data.data
    }
    if (rulesRes.data.success) {
      modsecRules.value = rulesRes.data.data.rules || []
    }
    if (logRes.data.success) {
      modsecAuditLog.value = logRes.data.data.entries || []
    }
  } catch (e) {
    toast.error('Failed to load ModSecurity data')
  } finally {
    modsecLoading.value = false
  }
}

const setModsecMode = async (mode) => {
  submitting.value = true
  try {
    const response = await api.post('/modsec/mode', { mode })
    
    if (response.data.success) {
      toast.success(`ModSecurity mode set to ${mode}`)
      await fetchModsec()
    } else {
      toast.error(response.data.error || 'Failed to set mode')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to set mode')
  } finally {
    submitting.value = false
  }
}

const toggleRule = async (rule, enable) => {
  submitting.value = true
  try {
    const endpoint = enable 
      ? `/modsec/rules/${rule.id || rule.name}/enable`
      : `/modsec/rules/${rule.id || rule.name}/disable`
    
    const response = await api.post(endpoint)
    
    if (response.data.success) {
      toast.success(`Rule ${enable ? 'enabled' : 'disabled'}`)
      await fetchModsec()
    } else {
      toast.error(response.data.error || 'Failed to toggle rule')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to toggle rule')
  } finally {
    submitting.value = false
  }
}

// ============================================
// CPGuard Functions
// ============================================
const fetchCpguard = async () => {
  cpguardLoading.value = true
  try {
    const response = await api.get('/cpguard/status')
    
    if (response.data.success) {
      cpguardStatus.value = response.data.data
      
      // If installed, fetch additional data
      if (cpguardStatus.value.installed) {
        await Promise.all([
          fetchCpguardLicense(),
          fetchCpguardLists(),
          fetchCpguardConfig()
        ])
      }
    }
  } catch (e) {
    // CPGuard might not be installed
    cpguardStatus.value = { installed: false }
  } finally {
    cpguardLoading.value = false
  }
}

const fetchCpguardLicense = async () => {
  try {
    const response = await api.get('/cpguard/license')
    if (response.data.success) {
      cpguardLicense.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch CPGuard license', e)
  }
}

const fetchCpguardLists = async () => {
  try {
    const response = await api.get('/cpguard/lists')
    if (response.data.success) {
      cpguardLists.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch CPGuard lists', e)
  }
}

const fetchCpguardConfig = async () => {
  try {
    const response = await api.get('/cpguard/config')
    if (response.data.success) {
      cpguardConfig.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch CPGuard config', e)
  }
}

const installCpguard = async () => {
  if (!cpguardInstallForm.value.license_key) {
    toast.error('License key is required')
    return
  }
  
  submitting.value = true
  try {
    const response = await api.post('/cpguard/install', {
      license_key: cpguardInstallForm.value.license_key
    })
    
    if (response.data.success) {
      toast.success('CPGuard installed successfully')
      cpguardInstallModal.value = false
      cpguardInstallForm.value = { license_key: '' }
      await fetchCpguard()
    } else {
      toast.error(response.data.error || 'Installation failed')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Installation failed')
  } finally {
    submitting.value = false
  }
}

const updateCpguardLicense = async () => {
  if (!cpguardLicenseForm.value.license_key) {
    toast.error('License key is required')
    return
  }
  
  submitting.value = true
  try {
    const response = await api.put('/cpguard/license', {
      license_key: cpguardLicenseForm.value.license_key
    })
    
    if (response.data.success) {
      toast.success('License updated successfully')
      cpguardLicenseModal.value = false
      cpguardLicenseForm.value = { license_key: '' }
      await fetchCpguardLicense()
    } else {
      toast.error(response.data.error || 'Failed to update license')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update license')
  } finally {
    submitting.value = false
  }
}

const addCpguardWhitelistIp = async () => {
  if (!cpguardWhitelistIpForm.value.ip) {
    toast.error('IP address is required')
    return
  }
  
  submitting.value = true
  try {
    const response = await api.post('/cpguard/whitelist/ip', {
      ip: cpguardWhitelistIpForm.value.ip
    })
    
    if (response.data.success) {
      toast.success('IP added to whitelist')
      cpguardAddWhitelistIpModal.value = false
      cpguardWhitelistIpForm.value = { ip: '' }
      await fetchCpguardLists()
    } else {
      toast.error(response.data.error || 'Failed to add IP')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to add IP')
  } finally {
    submitting.value = false
  }
}

const addCpguardWhitelistDomain = async () => {
  if (!cpguardWhitelistDomainForm.value.domain) {
    toast.error('Domain is required')
    return
  }
  
  submitting.value = true
  try {
    const response = await api.post('/cpguard/whitelist/domain', {
      domain: cpguardWhitelistDomainForm.value.domain
    })
    
    if (response.data.success) {
      toast.success('Domain added to whitelist')
      cpguardAddWhitelistDomainModal.value = false
      cpguardWhitelistDomainForm.value = { domain: '' }
      await fetchCpguardLists()
    } else {
      toast.error(response.data.error || 'Failed to add domain')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to add domain')
  } finally {
    submitting.value = false
  }
}

const addCpguardBlacklistIp = async () => {
  if (!cpguardBlacklistIpForm.value.ip) {
    toast.error('IP address is required')
    return
  }
  
  submitting.value = true
  try {
    const response = await api.post('/cpguard/blacklist/ip', {
      ip: cpguardBlacklistIpForm.value.ip
    })
    
    if (response.data.success) {
      toast.success('IP added to blacklist')
      cpguardAddBlacklistIpModal.value = false
      cpguardBlacklistIpForm.value = { ip: '' }
      await fetchCpguardLists()
    } else {
      toast.error(response.data.error || 'Failed to add IP')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to add IP')
  } finally {
    submitting.value = false
  }
}

const removeCpguardListEntry = async () => {
  const { type, listType, value } = cpguardRemoveModal.value
  if (!type || !listType || !value) return
  
  submitting.value = true
  try {
    const endpoint = listType === 'whitelist' 
      ? `/cpguard/whitelist/${type}/${encodeURIComponent(value)}`
      : `/cpguard/blacklist/${type}/${encodeURIComponent(value)}`
    
    const response = await api.delete(endpoint)
    
    if (response.data.success) {
      toast.success('Entry removed successfully')
      cpguardRemoveModal.value = { show: false, type: '', listType: '', value: '' }
      await fetchCpguardLists()
    } else {
      toast.error(response.data.error || 'Failed to remove entry')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to remove entry')
  } finally {
    submitting.value = false
  }
}

const toggleCpguardModule = async (module, enabled) => {
  submitting.value = true
  try {
    const response = await api.post('/cpguard/toggle', {
      module,
      enabled
    })
    
    if (response.data.success) {
      toast.success(`${module} ${enabled ? 'enabled' : 'disabled'} successfully`)
      await fetchCpguard()
    } else {
      toast.error(response.data.error || 'Failed to toggle module')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to toggle module')
  } finally {
    submitting.value = false
  }
}

const restartCpguardService = async (action = 'restart') => {
  submitting.value = true
  try {
    const response = await api.post('/cpguard/service', { action })
    
    if (response.data.success) {
      toast.success(`Service ${action}ed successfully`)
      await fetchCpguard()
    } else {
      toast.error(response.data.error || `Failed to ${action} service`)
    }
  } catch (e) {
    toast.error(e.response?.data?.error || `Failed to ${action} service`)
  } finally {
    submitting.value = false
  }
}

const triggerCpguardScan = async () => {
  submitting.value = true
  try {
    const response = await api.post('/cpguard/scan', {
      path: cpguardScanForm.value.path,
      background: cpguardScanForm.value.background
    })
    
    if (response.data.success) {
      toast.success(cpguardScanForm.value.background ? 'Scan started in background' : 'Scan completed')
      cpguardScanModal.value = false
      cpguardScanForm.value = { path: '/home', background: true }
    } else {
      toast.error(response.data.error || 'Failed to start scan')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to start scan')
  } finally {
    submitting.value = false
  }
}

// ============================================
// Tab Change Handler
// ============================================
const onTabChange = (tab) => {
  activeTab.value = tab
  
  if (tab === 'modsec' && !modsecStatus.value) {
    fetchModsec()
  } else if (tab === 'cpguard' && !cpguardStatus.value) {
    fetchCpguard()
  } else if (tab === 'dep_scan' && depScans.value.length === 0) {
    fetchDepScans()
  }
}

// ============================================
// Init
// ============================================
onMounted(async () => {
  await Promise.all([fetchFail2ban(), fetchFirewall()])
  loading.value = false
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Security</h1>
        <p class="text-surface-500 text-sm mt-1 hidden sm:block">Fail2ban, Firewall, ModSecurity, and CPGuard management</p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-surface-200 dark:border-surface-700 mb-6 overflow-x-auto scrollbar-none">
      <nav class="flex gap-1 -mb-px min-w-max">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="onTabChange(tab.id)"
          :class="[
            'flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
            activeTab === tab.id
              ? 'border-primary-500 text-primary-600 dark:text-primary-400'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
          :title="tab.label"
        >
          <span class="material-symbols-rounded text-lg">{{ tab.icon }}</span>
          <span class="hidden sm:inline">{{ tab.label }}</span>
        </button>
      </nav>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner"></span>
    </div>

    <!-- ============================================ -->
    <!-- Fail2ban Tab -->
    <!-- ============================================ -->
    <div v-else-if="activeTab === 'fail2ban'" class="space-y-4 sm:space-y-6">
      <!-- Header Actions -->
      <div class="flex flex-col sm:flex-row justify-between gap-3 sm:items-center">
        <span v-if="getCacheAge(CACHE_KEYS.FAIL2BAN_JAILS) !== 'not cached'" class="text-xs text-surface-400 hidden sm:block">
          <span class="material-symbols-rounded text-sm align-middle">schedule</span>
          Updated {{ getCacheAge(CACHE_KEYS.FAIL2BAN_JAILS) }}
        </span>
        <span v-else class="hidden sm:block"></span>
        <div class="flex gap-2">
          <button @click="fetchFail2ban(true)" class="btn-secondary" :disabled="submitting">
            <span class="material-symbols-rounded">refresh</span>
            <span class="hidden sm:inline">Refresh</span>
          </button>
          <button @click="createJailModal = true" class="btn-primary">
            <span class="material-symbols-rounded">add</span>
            <span class="hidden sm:inline">Create Jail</span>
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
        <!-- Jails list -->
        <div class="card">
          <div class="card-header flex items-center justify-between">
            <h3 class="font-medium">Jails</h3>
            <StatusBadge :status="fail2banStatus?.running ? 'active' : 'inactive'" />
          </div>
          <div class="divide-y divide-surface-100 dark:divide-surface-800">
            <button
              v-for="jail in jails"
              :key="jail.name"
              @click="selectJail(jail)"
              :class="[
                'w-full px-4 py-3 flex items-center justify-between text-left transition-colors',
                selectedJail?.name === jail.name 
                  ? 'bg-primary-50 dark:bg-primary-500/10' 
                  : 'hover:bg-surface-50 dark:hover:bg-surface-800'
              ]"
            >
              <div>
                <p class="font-medium">{{ jail.name }}</p>
                <p class="text-sm text-surface-500">{{ jail.currently_banned }} banned</p>
              </div>
              <span v-if="jail.currently_banned > 0" class="badge badge-danger">
                {{ jail.currently_banned }}
              </span>
            </button>
            <div v-if="!jails.length" class="px-4 py-8 text-center text-surface-400">
              No jails configured
            </div>
          </div>
        </div>

        <!-- Jail details -->
        <div class="lg:col-span-2">
          <template v-if="selectedJail">
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <h3 class="font-medium">{{ selectedJail.name }}</h3>
                  <Toggle 
                    :modelValue="selectedJail.enabled !== false"
                    @update:modelValue="toggleJailEnabled(selectedJail)"
                    :disabled="submitting"
                  />
                </div>
                <div class="flex gap-2">
                  <button 
                    @click="banIpModal = { show: true, ip: '' }" 
                    class="btn-secondary btn-sm"
                  >
                    <span class="material-symbols-rounded">block</span>
                    Ban IP
                  </button>
                  <button 
                    @click="openEditJail(selectedJail)"
                    class="btn-secondary btn-sm"
                  >
                    <span class="material-symbols-rounded">edit</span>
                    Edit
                  </button>
                  <button 
                    @click="deleteJailModal = { show: true, jail: selectedJail }"
                    class="btn-ghost btn-sm text-red-500"
                  >
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </div>
              
              <!-- Stats -->
              <div class="p-3 sm:p-4 grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 border-b border-surface-100 dark:border-surface-800">
                <div>
                  <div class="text-xl sm:text-2xl font-semibold">{{ selectedJail.currently_failed }}</div>
                  <div class="text-xs sm:text-sm text-surface-500">Currently Failed</div>
                </div>
                <div>
                  <div class="text-xl sm:text-2xl font-semibold">{{ selectedJail.total_failed }}</div>
                  <div class="text-xs sm:text-sm text-surface-500">Total Failed</div>
                </div>
                <div>
                  <div class="text-xl sm:text-2xl font-semibold text-red-500">{{ selectedJail.currently_banned }}</div>
                  <div class="text-xs sm:text-sm text-surface-500">Currently Banned</div>
                </div>
                <div>
                  <div class="text-xl sm:text-2xl font-semibold">{{ selectedJail.total_banned }}</div>
                  <div class="text-xs sm:text-sm text-surface-500">Total Banned</div>
                </div>
              </div>

              <!-- Banned IPs -->
              <div class="p-4">
                <h4 class="font-medium mb-3">Banned IPs</h4>
                <div v-if="selectedJail.banned_ips?.length" class="space-y-2">
                  <div
                    v-for="ip in selectedJail.banned_ips"
                    :key="ip"
                    class="flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-800 rounded-xl"
                  >
                    <code class="text-sm font-mono">{{ ip }}</code>
                    <button 
                      @click="unbanModal = { show: true, ip }"
                      class="btn-ghost btn-sm text-green-500"
                    >
                      <span class="material-symbols-rounded">lock_open</span>
                      Unban
                    </button>
                  </div>
                </div>
                <p v-else class="text-surface-400 text-sm">No IPs currently banned</p>
              </div>
            </div>
          </template>
          
          <div v-else class="card p-12 text-center text-surface-400">
            <span class="material-symbols-rounded text-4xl mb-2 block">block</span>
            Select a jail to view details
          </div>
        </div>
      </div>
    </div>

    <!-- ============================================ -->
    <!-- Firewall Tab -->
    <!-- ============================================ -->
    <div v-else-if="activeTab === 'firewall'" class="space-y-6">
      <!-- Header Actions -->
      <div class="flex justify-between items-center">
        <span v-if="getCacheAge(CACHE_KEYS.FIREWALL_ZONES) !== 'not cached'" class="text-xs text-surface-400">
          <span class="material-symbols-rounded text-sm align-middle">schedule</span>
          Updated {{ getCacheAge(CACHE_KEYS.FIREWALL_ZONES) }}
        </span>
        <span v-else></span>
        <div class="flex gap-2">
          <button @click="fetchFirewall(true)" class="btn-secondary" :disabled="submitting">
            <span class="material-symbols-rounded">refresh</span>
            Refresh
          </button>
          <button @click="reloadFirewall" class="btn-secondary" :disabled="submitting">
            <span class="material-symbols-rounded">restart_alt</span>
            Reload Firewall
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Zones list -->
        <div class="card">
          <div class="card-header flex items-center justify-between">
            <h3 class="font-medium">Zones</h3>
            <StatusBadge :status="firewallStatus?.running ? 'active' : 'inactive'" />
          </div>
          <div class="divide-y divide-surface-100 dark:divide-surface-800">
            <button
              v-for="zone in zones"
              :key="zone.name"
              @click="selectedZone = zone"
              :class="[
                'w-full px-4 py-3 flex items-center justify-between text-left transition-colors',
                selectedZone?.name === zone.name 
                  ? 'bg-primary-50 dark:bg-primary-500/10' 
                  : 'hover:bg-surface-50 dark:hover:bg-surface-800'
              ]"
            >
              <div>
                <p class="font-medium">{{ zone.name }}</p>
                <div class="flex gap-2 mt-1">
                  <span v-if="zone.default" class="badge badge-info">default</span>
                  <span v-if="zone.active" class="badge badge-success">active</span>
                </div>
              </div>
            </button>
            <div v-if="!zones.length" class="px-4 py-8 text-center text-surface-400">
              No zones found
            </div>
          </div>
        </div>

        <!-- Zone details -->
        <div class="lg:col-span-2 space-y-4">
          <template v-if="selectedZone">
            <!-- Services -->
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <h3 class="font-medium">Services</h3>
                <button @click="addServiceModal = true" class="btn-primary btn-sm">
                  <span class="material-symbols-rounded">add</span>
                  Add Service
                </button>
              </div>
              <div class="p-4">
                <div v-if="selectedZone.services?.length" class="flex flex-wrap gap-2">
                  <span
                    v-for="service in selectedZone.services"
                    :key="service"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-400 text-sm"
                  >
                    {{ service }}
                    <button 
                      @click="removeServiceModal = { show: true, service }"
                      class="ml-1 hover:text-red-500 transition-colors"
                    >
                      <span class="material-symbols-rounded text-base">close</span>
                    </button>
                  </span>
                </div>
                <p v-else class="text-surface-400 text-sm">No services allowed</p>
              </div>
            </div>

            <!-- Ports -->
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <h3 class="font-medium">Ports</h3>
                <button @click="addPortModal = true" class="btn-primary btn-sm">
                  <span class="material-symbols-rounded">add</span>
                  Add Port
                </button>
              </div>
              <div class="p-4">
                <div v-if="selectedZone.ports?.length" class="flex flex-wrap gap-2">
                  <span
                    v-for="port in selectedZone.ports"
                    :key="port"
                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-400 text-sm font-mono"
                  >
                    {{ port }}
                    <button 
                      @click="removePortModal = { show: true, port }"
                      class="ml-1 hover:text-red-500 transition-colors"
                    >
                      <span class="material-symbols-rounded text-base">close</span>
                    </button>
                  </span>
                </div>
                <p v-else class="text-surface-400 text-sm">No additional ports open</p>
              </div>
            </div>

            <!-- Interfaces -->
            <div class="card">
              <div class="card-header">
                <h3 class="font-medium">Interfaces</h3>
              </div>
              <div class="p-4">
                <div v-if="selectedZone.interfaces?.length" class="flex flex-wrap gap-2">
                  <span
                    v-for="iface in selectedZone.interfaces"
                    :key="iface"
                    class="badge badge-neutral font-mono"
                  >
                    {{ iface }}
                  </span>
                </div>
                <p v-else class="text-surface-400 text-sm">No interfaces bound</p>
              </div>
            </div>
          </template>

          <div v-else class="card p-12 text-center text-surface-400">
            <span class="material-symbols-rounded text-4xl mb-2 block">local_fire_department</span>
            Select a zone to view details
          </div>
        </div>
      </div>
    </div>

    <!-- ============================================ -->
    <!-- ModSecurity Tab -->
    <!-- ============================================ -->
    <div v-else-if="activeTab === 'modsec'" class="space-y-6">
      <div v-if="modsecLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <template v-else>
        <!-- Status Card -->
        <div class="card p-6">
          <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
              <div :class="[
                'w-14 h-14 rounded-2xl flex items-center justify-center',
                modsecStatus?.enabled 
                  ? 'bg-green-100 dark:bg-green-500/20' 
                  : 'bg-surface-100 dark:bg-surface-800'
              ]">
                <span :class="[
                  'material-symbols-rounded text-2xl',
                  modsecStatus?.enabled ? 'text-green-600 dark:text-green-400' : 'text-surface-400'
                ]">security</span>
              </div>
              <div>
                <h3 class="font-semibold text-lg">ModSecurity WAF</h3>
                <p class="text-surface-500">Web Application Firewall</p>
              </div>
            </div>
            <StatusBadge :status="modsecStatus?.enabled ? 'active' : 'inactive'" />
          </div>

          <!-- Mode Selection -->
          <div class="border-t border-surface-100 dark:border-surface-800 pt-6">
            <h4 class="font-medium mb-4">Protection Mode</h4>
            <div class="flex flex-wrap gap-3">
              <button 
                @click="setModsecMode('On')"
                :class="[
                  'px-4 py-2 rounded-xl font-medium transition-all',
                  modsecStatus?.mode === 'On'
                    ? 'bg-green-500 text-white'
                    : 'bg-surface-100 dark:bg-surface-800 hover:bg-surface-200 dark:hover:bg-surface-700'
                ]"
                :disabled="submitting"
              >
                <span class="material-symbols-rounded align-middle mr-1">shield</span>
                On (Block)
              </button>
              <button 
                @click="setModsecMode('DetectionOnly')"
                :class="[
                  'px-4 py-2 rounded-xl font-medium transition-all',
                  modsecStatus?.mode === 'DetectionOnly'
                    ? 'bg-amber-500 text-white'
                    : 'bg-surface-100 dark:bg-surface-800 hover:bg-surface-200 dark:hover:bg-surface-700'
                ]"
                :disabled="submitting"
              >
                <span class="material-symbols-rounded align-middle mr-1">visibility</span>
                Detection Only
              </button>
              <button 
                @click="setModsecMode('Off')"
                :class="[
                  'px-4 py-2 rounded-xl font-medium transition-all',
                  modsecStatus?.mode === 'Off'
                    ? 'bg-red-500 text-white'
                    : 'bg-surface-100 dark:bg-surface-800 hover:bg-surface-200 dark:hover:bg-surface-700'
                ]"
                :disabled="submitting"
              >
                <span class="material-symbols-rounded align-middle mr-1">shield_locked</span>
                Off
              </button>
            </div>
          </div>
        </div>

        <!-- Rules -->
        <div class="card">
          <div class="card-header">
            <h3 class="font-medium">Rules</h3>
          </div>
          <div v-if="modsecRules.length" class="divide-y divide-surface-100 dark:divide-surface-800">
            <div 
              v-for="rule in modsecRules" 
              :key="rule.id || rule.name"
              class="px-4 py-3 flex items-center justify-between"
            >
              <div>
                <p class="font-medium">{{ rule.name || rule.id }}</p>
                <p v-if="rule.description" class="text-sm text-surface-500">{{ rule.description }}</p>
              </div>
              <Toggle 
                :modelValue="rule.enabled" 
                @update:modelValue="toggleRule(rule, $event)"
              />
            </div>
          </div>
          <div v-else class="p-8 text-center text-surface-400">
            <span class="material-symbols-rounded text-4xl mb-2 block">rule</span>
            No rules configured
          </div>
        </div>

        <!-- Audit Log -->
        <div class="card">
          <div class="card-header flex items-center justify-between">
            <h3 class="font-medium">Recent Audit Log</h3>
            <button @click="fetchModsec" class="btn-ghost btn-sm">
              <span class="material-symbols-rounded">refresh</span>
            </button>
          </div>
          <div v-if="modsecAuditLog.length" class="overflow-x-auto">
            <table class="table">
              <thead>
                <tr class="bg-surface-50 dark:bg-surface-800/50">
                  <th>Time</th>
                  <th>IP</th>
                  <th>Rule</th>
                  <th>Message</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(entry, idx) in modsecAuditLog.slice(0, 20)" :key="idx">
                  <td class="text-sm text-surface-500 whitespace-nowrap">{{ entry.timestamp }}</td>
                  <td class="font-mono text-sm">{{ entry.ip }}</td>
                  <td><span class="badge badge-warning">{{ entry.rule_id }}</span></td>
                  <td class="text-sm max-w-md truncate" :title="entry.message">{{ entry.message }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="p-8 text-center text-surface-400">
            <span class="material-symbols-rounded text-4xl mb-2 block">history</span>
            No audit log entries
          </div>
        </div>
      </template>
    </div>

    <!-- ============================================ -->
    <!-- CPGuard Tab -->
    <!-- ============================================ -->
    <div v-else-if="activeTab === 'cpguard'" class="space-y-6">
      <div v-if="cpguardLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <!-- Not Installed State -->
      <template v-else-if="cpguardStatus?.installed === false">
        <div class="card p-8 text-center">
          <span class="material-symbols-rounded text-5xl text-surface-300 mb-4 block">shield_locked</span>
          <h3 class="text-lg font-medium mb-2">CPGuard Not Installed</h3>
          <p class="text-surface-500 mb-6">CPGuard WAF is not installed on this server. Install it to protect your server from threats.</p>
          <button @click="cpguardInstallModal = true" class="btn-primary">
            <span class="material-symbols-rounded">download</span>
            Install CPGuard
          </button>
        </div>
      </template>

      <!-- Installed State -->
      <template v-else>
        <!-- Header Actions -->
        <div class="flex flex-col sm:flex-row justify-between gap-3 sm:items-center">
          <div class="flex items-center gap-2">
            <span v-if="cpguardStatus?.version" class="badge badge-info">v{{ cpguardStatus.version }}</span>
            <StatusBadge :status="cpguardStatus?.service_status === 'running' ? 'active' : 'inactive'" />
          </div>
          <div class="flex flex-wrap gap-2">
            <button @click="fetchCpguard" class="btn-secondary" :disabled="submitting">
              <span class="material-symbols-rounded">refresh</span>
              <span class="hidden sm:inline">Refresh</span>
            </button>
            <button @click="cpguardScanModal = true" class="btn-secondary" :disabled="submitting">
              <span class="material-symbols-rounded">scan</span>
              <span class="hidden sm:inline">Scan</span>
            </button>
            <button @click="restartCpguardService('restart')" class="btn-secondary" :disabled="submitting">
              <span class="material-symbols-rounded">restart_alt</span>
              <span class="hidden sm:inline">Restart</span>
            </button>
          </div>
        </div>

        <!-- Sub-tabs for CPGuard sections -->
        <div class="flex flex-wrap gap-2 border-b border-surface-200 dark:border-surface-700 pb-3">
          <button 
            v-for="section in [
              { id: 'status', label: 'Status', icon: 'dashboard' },
              { id: 'license', label: 'License', icon: 'key' },
              { id: 'lists', label: 'Lists', icon: 'list' },
              { id: 'config', label: 'Config', icon: 'settings' }
            ]"
            :key="section.id"
            @click="cpguardActiveSection = section.id"
            :class="[
              'px-3 py-1.5 rounded-full text-sm font-medium transition-colors flex items-center gap-1.5',
              cpguardActiveSection === section.id
                ? 'bg-primary-500 text-white'
                : 'bg-surface-100 dark:bg-surface-800 hover:bg-surface-200 dark:hover:bg-surface-700'
            ]"
          >
            <span class="material-symbols-rounded text-base">{{ section.icon }}</span>
            {{ section.label }}
          </button>
        </div>

        <!-- Status Section -->
        <template v-if="cpguardActiveSection === 'status'">
          <!-- Stats Grid -->
          <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="card p-4">
              <p class="text-sm text-surface-500">Blocked Today</p>
              <p class="text-2xl font-semibold text-red-500">{{ cpguardStatus?.blocked_today || 0 }}</p>
            </div>
            <div class="card p-4">
              <p class="text-sm text-surface-500">Blocked Week</p>
              <p class="text-2xl font-semibold">{{ cpguardStatus?.blocked_week || 0 }}</p>
            </div>
            <div class="card p-4">
              <p class="text-sm text-surface-500">Blocked Month</p>
              <p class="text-2xl font-semibold">{{ cpguardStatus?.blocked_month || 0 }}</p>
            </div>
            <div class="card p-4">
              <p class="text-sm text-surface-500">Active Rules</p>
              <p class="text-2xl font-semibold">{{ cpguardStatus?.active_rules || 0 }}</p>
            </div>
            <div class="card p-4">
              <p class="text-sm text-surface-500">Last Scan</p>
              <p class="text-lg font-semibold">{{ cpguardStatus?.last_scan || 'Never' }}</p>
            </div>
          </div>

          <!-- Protection Modules -->
          <div class="card">
            <div class="card-header">
              <h3 class="font-medium">Protection Modules</h3>
            </div>
            <div class="divide-y divide-surface-100 dark:divide-surface-800">
              <div class="px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <span class="material-symbols-rounded text-surface-400">shield</span>
                  <div>
                    <span class="font-medium">WAF Protection</span>
                    <p class="text-xs text-surface-500">Web Application Firewall (ModSecurity)</p>
                  </div>
                </div>
                <StatusBadge :status="cpguardStatus?.waf_enabled ? 'active' : 'inactive'" />
              </div>
              <div class="px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <span class="material-symbols-rounded text-surface-400">bug_report</span>
                  <div>
                    <span class="font-medium">Malware Scanner</span>
                    <p class="text-xs text-surface-500">Scan files for malware</p>
                  </div>
                </div>
                <StatusBadge :status="cpguardStatus?.malware_scanner ? 'active' : 'inactive'" />
              </div>
              <div class="px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <span class="material-symbols-rounded text-surface-400">lock</span>
                  <div>
                    <span class="font-medium">Brute Force Protection</span>
                    <p class="text-xs text-surface-500">Block repeated login attempts</p>
                  </div>
                </div>
                <StatusBadge :status="cpguardStatus?.brute_force ? 'active' : 'inactive'" />
              </div>
              <div class="px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <span class="material-symbols-rounded text-surface-400">smart_toy</span>
                  <div>
                    <span class="font-medium">CAPTCHA Protection</span>
                    <p class="text-xs text-surface-500">Challenge suspicious requests</p>
                  </div>
                </div>
                <StatusBadge :status="cpguardStatus?.captcha ? 'active' : 'inactive'" />
              </div>
              <div class="px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <span class="material-symbols-rounded text-surface-400">robot</span>
                  <div>
                    <span class="font-medium">Bot Control</span>
                    <p class="text-xs text-surface-500">Block bad bots and crawlers</p>
                  </div>
                </div>
                <StatusBadge :status="cpguardStatus?.bot_control ? 'active' : 'inactive'" />
              </div>
              <div class="px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <span class="material-symbols-rounded text-surface-400">cleaning_services</span>
                  <div>
                    <span class="font-medium">Auto Clean</span>
                    <p class="text-xs text-surface-500">Automatically clean infected files</p>
                  </div>
                </div>
                <StatusBadge :status="cpguardStatus?.auto_clean ? 'active' : 'inactive'" />
              </div>
              <div class="px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <span class="material-symbols-rounded text-surface-400">public</span>
                  <div>
                    <span class="font-medium">Country Blocking</span>
                    <p class="text-xs text-surface-500">Block traffic by country</p>
                  </div>
                </div>
                <StatusBadge :status="cpguardStatus?.country_blocking ? 'active' : 'inactive'" />
              </div>
              <div class="px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <span class="material-symbols-rounded text-surface-400">dns</span>
                  <div>
                    <span class="font-medium">IPDB Firewall</span>
                    <p class="text-xs text-surface-500">Block known bad IPs</p>
                  </div>
                </div>
                <StatusBadge :status="cpguardStatus?.ipdb_firewall ? 'active' : 'inactive'" />
              </div>
            </div>
          </div>
        </template>

        <!-- License Section -->
        <template v-else-if="cpguardActiveSection === 'license'">
          <div class="card">
            <div class="card-header flex items-center justify-between">
              <h3 class="font-medium">License Information</h3>
              <button @click="cpguardLicenseModal = true" class="btn-secondary btn-sm">
                <span class="material-symbols-rounded">edit</span>
                Update License
              </button>
            </div>
            <div class="p-4 space-y-4">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
                  <p class="text-sm text-surface-500 mb-1">License Key</p>
                  <p class="font-mono text-sm">{{ cpguardLicense?.license_key || 'Not available' }}</p>
                </div>
                <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
                  <p class="text-sm text-surface-500 mb-1">Status</p>
                  <StatusBadge :status="cpguardLicense?.status === 'active' || cpguardLicense?.status === 'valid' ? 'active' : 'inactive'" />
                </div>
                <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
                  <p class="text-sm text-surface-500 mb-1">Expiry Date</p>
                  <p class="font-medium">{{ cpguardLicense?.expiry_date || 'Unknown' }}</p>
                </div>
                <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
                  <p class="text-sm text-surface-500 mb-1">Server IP</p>
                  <p class="font-mono text-sm">{{ cpguardLicense?.server_ip || 'Unknown' }}</p>
                </div>
              </div>
            </div>
          </div>
        </template>

        <!-- Lists Section -->
        <template v-else-if="cpguardActiveSection === 'lists'">
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Whitelist IPs -->
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <h3 class="font-medium flex items-center gap-2">
                  <span class="material-symbols-rounded text-green-500">check_circle</span>
                  Whitelist IPs
                </h3>
                <button @click="cpguardAddWhitelistIpModal = true" class="btn-primary btn-sm">
                  <span class="material-symbols-rounded">add</span>
                  Add
                </button>
              </div>
              <div class="p-4">
                <div v-if="cpguardLists.whitelist_ips?.length" class="space-y-2 max-h-64 overflow-y-auto">
                  <div 
                    v-for="ip in cpguardLists.whitelist_ips" 
                    :key="ip"
                    class="flex items-center justify-between p-2 bg-surface-50 dark:bg-surface-800 rounded-lg"
                  >
                    <code class="text-sm font-mono">{{ ip }}</code>
                    <button 
                      @click="cpguardRemoveModal = { show: true, type: 'ip', listType: 'whitelist', value: ip }"
                      class="btn-ghost btn-sm text-red-500"
                    >
                      <span class="material-symbols-rounded">close</span>
                    </button>
                  </div>
                </div>
                <p v-else class="text-surface-400 text-sm text-center py-4">No whitelisted IPs</p>
              </div>
            </div>

            <!-- Whitelist Domains -->
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <h3 class="font-medium flex items-center gap-2">
                  <span class="material-symbols-rounded text-green-500">language</span>
                  Whitelist Domains
                </h3>
                <button @click="cpguardAddWhitelistDomainModal = true" class="btn-primary btn-sm">
                  <span class="material-symbols-rounded">add</span>
                  Add
                </button>
              </div>
              <div class="p-4">
                <div v-if="cpguardLists.whitelist_domains?.length" class="space-y-2 max-h-64 overflow-y-auto">
                  <div 
                    v-for="domain in cpguardLists.whitelist_domains" 
                    :key="domain"
                    class="flex items-center justify-between p-2 bg-surface-50 dark:bg-surface-800 rounded-lg"
                  >
                    <code class="text-sm font-mono">{{ domain }}</code>
                    <button 
                      @click="cpguardRemoveModal = { show: true, type: 'domain', listType: 'whitelist', value: domain }"
                      class="btn-ghost btn-sm text-red-500"
                    >
                      <span class="material-symbols-rounded">close</span>
                    </button>
                  </div>
                </div>
                <p v-else class="text-surface-400 text-sm text-center py-4">No whitelisted domains</p>
              </div>
            </div>

            <!-- Blacklist IPs (from file) -->
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <h3 class="font-medium flex items-center gap-2">
                  <span class="material-symbols-rounded text-red-500">block</span>
                  Blacklist IPs
                </h3>
                <button @click="cpguardAddBlacklistIpModal = true" class="btn-primary btn-sm">
                  <span class="material-symbols-rounded">add</span>
                  Add
                </button>
              </div>
              <div class="p-4">
                <div v-if="cpguardLists.blacklist_ips?.length" class="space-y-2 max-h-64 overflow-y-auto">
                  <div 
                    v-for="ip in cpguardLists.blacklist_ips" 
                    :key="ip"
                    class="flex items-center justify-between p-2 bg-surface-50 dark:bg-surface-800 rounded-lg"
                  >
                    <code class="text-sm font-mono">{{ ip }}</code>
                    <button 
                      @click="cpguardRemoveModal = { show: true, type: 'ip', listType: 'blacklist', value: ip }"
                      class="btn-ghost btn-sm text-red-500"
                    >
                      <span class="material-symbols-rounded">close</span>
                    </button>
                  </div>
                </div>
                <p v-else class="text-surface-400 text-sm text-center py-4">No permanent blacklisted IPs</p>
              </div>
            </div>

            <!-- Temp Bans (Active Blocks) -->
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <h3 class="font-medium flex items-center gap-2">
                  <span class="material-symbols-rounded text-orange-500">timer</span>
                  Temp Bans (Active)
                </h3>
                <span class="badge badge-warning">{{ cpguardLists.temp_bans?.length || 0 }}</span>
              </div>
              <div class="p-4">
                <div v-if="cpguardLists.temp_bans?.length" class="space-y-2 max-h-64 overflow-y-auto">
                  <div 
                    v-for="ban in cpguardLists.temp_bans" 
                    :key="ban.ip"
                    class="flex items-center justify-between p-2 bg-surface-50 dark:bg-surface-800 rounded-lg"
                  >
                    <div class="flex-1">
                      <code class="text-sm font-mono">{{ ban.ip }}</code>
                      <div class="text-xs text-surface-400 mt-1">
                        <span v-if="ban.reason">{{ ban.reason }}</span>
                        <span v-if="ban.country" class="ml-2 badge badge-sm">{{ ban.country }}</span>
                      </div>
                    </div>
                  </div>
                </div>
                <p v-else class="text-surface-400 text-sm text-center py-4">No active temp bans</p>
              </div>
            </div>

            <!-- Blacklist Files -->
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <h3 class="font-medium flex items-center gap-2">
                  <span class="material-symbols-rounded text-red-500">folder_off</span>
                  Blacklist Files
                </h3>
              </div>
              <div class="p-4">
                <div v-if="cpguardLists.blacklist_files?.length" class="space-y-2 max-h-64 overflow-y-auto">
                  <div 
                    v-for="file in cpguardLists.blacklist_files" 
                    :key="file"
                    class="flex items-center justify-between p-2 bg-surface-50 dark:bg-surface-800 rounded-lg"
                  >
                    <code class="text-sm font-mono truncate flex-1" :title="file">{{ file }}</code>
                    <button 
                      @click="cpguardRemoveModal = { show: true, type: 'file', listType: 'blacklist', value: file }"
                      class="btn-ghost btn-sm text-red-500 flex-shrink-0"
                    >
                      <span class="material-symbols-rounded">close</span>
                    </button>
                  </div>
                </div>
                <p v-else class="text-surface-400 text-sm text-center py-4">No blacklisted files</p>
              </div>
            </div>

            <!-- Allowed Countries -->
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <h3 class="font-medium flex items-center gap-2">
                  <span class="material-symbols-rounded text-green-500">public</span>
                  Allowed Countries
                </h3>
                <span class="badge badge-success">{{ cpguardLists.whitelist_countries?.length || 0 }}</span>
              </div>
              <div class="p-4">
                <div v-if="cpguardLists.whitelist_countries?.length" class="flex flex-wrap gap-2">
                  <span 
                    v-for="country in cpguardLists.whitelist_countries" 
                    :key="country"
                    class="badge badge-success"
                  >
                    {{ country }}
                  </span>
                </div>
                <p v-else class="text-surface-400 text-sm text-center py-4">No country whitelist configured</p>
              </div>
            </div>

            <!-- Blocked Countries -->
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <h3 class="font-medium flex items-center gap-2">
                  <span class="material-symbols-rounded text-red-500">public_off</span>
                  Blocked Countries
                </h3>
                <span class="badge badge-danger">{{ cpguardLists.blacklist_countries?.length || 0 }}</span>
              </div>
              <div class="p-4">
                <div v-if="cpguardLists.blacklist_countries?.length" class="flex flex-wrap gap-2">
                  <span 
                    v-for="country in cpguardLists.blacklist_countries" 
                    :key="country"
                    class="badge badge-danger"
                  >
                    {{ country }}
                  </span>
                </div>
                <p v-else class="text-surface-400 text-sm text-center py-4">No country blacklist configured</p>
              </div>
            </div>

            <!-- Whitelist URLs -->
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <h3 class="font-medium flex items-center gap-2">
                  <span class="material-symbols-rounded text-green-500">link</span>
                  Whitelist URLs
                </h3>
                <span class="badge badge-info">{{ cpguardLists.whitelist_urls?.length || 0 }}</span>
              </div>
              <div class="p-4">
                <div v-if="cpguardLists.whitelist_urls?.length" class="space-y-2 max-h-64 overflow-y-auto">
                  <div 
                    v-for="url in cpguardLists.whitelist_urls" 
                    :key="url"
                    class="p-2 bg-surface-50 dark:bg-surface-800 rounded-lg"
                  >
                    <code class="text-sm font-mono break-all">{{ url }}</code>
                  </div>
                </div>
                <p v-else class="text-surface-400 text-sm text-center py-4">No whitelisted URLs</p>
              </div>
            </div>

            <!-- Bad Bots -->
            <div class="card">
              <div class="card-header flex items-center justify-between">
                <h3 class="font-medium flex items-center gap-2">
                  <span class="material-symbols-rounded text-orange-500">smart_toy</span>
                  Bad Bots
                </h3>
                <span class="badge badge-warning">{{ cpguardLists.bad_bots?.length || 0 }}</span>
              </div>
              <div class="p-4">
                <div v-if="cpguardLists.bad_bots?.length" class="space-y-2 max-h-64 overflow-y-auto">
                  <div 
                    v-for="bot in cpguardLists.bad_bots" 
                    :key="bot"
                    class="p-2 bg-surface-50 dark:bg-surface-800 rounded-lg"
                  >
                    <code class="text-sm font-mono">{{ bot }}</code>
                  </div>
                </div>
                <p v-else class="text-surface-400 text-sm text-center py-4">No bad bots configured</p>
              </div>
            </div>
          </div>
        </template>

        <!-- Config Section -->
        <template v-else-if="cpguardActiveSection === 'config'">
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- WAF Config -->
            <div class="card">
              <div class="card-header">
                <h3 class="font-medium">WAF Configuration</h3>
              </div>
              <div class="p-4 space-y-3">
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Status</span>
                  <StatusBadge :status="cpguardConfig?.modules?.waf?.enabled ? 'active' : 'inactive'" />
                </div>
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Mode</span>
                  <span class="badge badge-info">{{ cpguardConfig?.modules?.waf?.mode || 'off' }}</span>
                </div>
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Rule Count</span>
                  <span class="font-mono">{{ cpguardConfig?.modules?.waf?.rule_count ?? 0 }}</span>
                </div>
              </div>
            </div>

            <!-- Scanner Config -->
            <div class="card">
              <div class="card-header">
                <h3 class="font-medium">Scanner Configuration</h3>
              </div>
              <div class="p-4 space-y-3">
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Status</span>
                  <StatusBadge :status="cpguardConfig?.modules?.scanner?.enabled ? 'active' : 'inactive'" />
                </div>
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Suspicious Action</span>
                  <span class="badge badge-warning">{{ cpguardConfig?.modules?.scanner?.suspicious_action || 'N/A' }}</span>
                </div>
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Virus Action</span>
                  <span class="badge badge-danger">{{ cpguardConfig?.modules?.scanner?.virus_action || 'N/A' }}</span>
                </div>
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Auto Clean</span>
                  <StatusBadge :status="cpguardConfig?.modules?.scanner?.auto_clean ? 'active' : 'inactive'" />
                </div>
              </div>
            </div>

            <!-- Brute Force Config -->
            <div class="card">
              <div class="card-header">
                <h3 class="font-medium">Brute Force Protection</h3>
              </div>
              <div class="p-4 space-y-3">
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Status</span>
                  <StatusBadge :status="cpguardConfig?.modules?.brute_force?.enabled ? 'active' : 'inactive'" />
                </div>
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Protected URLs</span>
                  <span class="font-mono">{{ cpguardConfig?.modules?.brute_force?.url_count ?? 0 }}</span>
                </div>
                <div v-if="cpguardConfig?.modules?.brute_force?.protected_urls?.length" class="mt-2">
                  <span class="text-surface-500 text-sm">URLs:</span>
                  <div class="flex flex-wrap gap-1 mt-1">
                    <code 
                      v-for="url in cpguardConfig.modules.brute_force.protected_urls" 
                      :key="url"
                      class="text-xs bg-surface-100 dark:bg-surface-800 px-2 py-1 rounded"
                    >{{ url }}</code>
                  </div>
                </div>
              </div>
            </div>

            <!-- Bot Control Config -->
            <div class="card">
              <div class="card-header">
                <h3 class="font-medium">Bot Control</h3>
              </div>
              <div class="p-4 space-y-3">
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Status</span>
                  <StatusBadge :status="cpguardConfig?.modules?.bot_control?.enabled ? 'active' : 'inactive'" />
                </div>
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Blocked Bots</span>
                  <span class="font-mono">{{ cpguardConfig?.modules?.bot_control?.bad_bot_count ?? 0 }}</span>
                </div>
              </div>
            </div>

            <!-- CAPTCHA Config -->
            <div class="card">
              <div class="card-header">
                <h3 class="font-medium">CAPTCHA Protection</h3>
              </div>
              <div class="p-4 space-y-3">
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Status</span>
                  <StatusBadge :status="cpguardConfig?.modules?.captcha?.enabled ? 'active' : 'inactive'" />
                </div>
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Type</span>
                  <span class="badge badge-info">{{ cpguardConfig?.modules?.captcha?.type || 'N/A' }}</span>
                </div>
              </div>
            </div>

            <!-- Country Blocking & IPDB (Not available in standard cPanel CPGuard) -->
            <div class="card">
              <div class="card-header">
                <h3 class="font-medium">Additional Features</h3>
              </div>
              <div class="p-4 space-y-3">
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">Country Blocking</span>
                  <StatusBadge :status="cpguardConfig?.modules?.country_blocking?.enabled ? 'active' : 'inactive'" />
                </div>
                <div class="flex justify-between items-center">
                  <span class="text-surface-500">IPDB Firewall</span>
                  <StatusBadge :status="cpguardConfig?.modules?.ipdb?.enabled ? 'active' : 'inactive'" />
                </div>
                <p class="text-xs text-surface-400 mt-2">
                  Configure additional features via WHM CPGuard plugin
                </p>
              </div>
            </div>
          </div>
        </template>
      </template>
    </div>

    <!-- ============================================ -->
    <!-- Dependency Scan Tab -->
    <!-- ============================================ -->
    <div v-else-if="activeTab === 'dep_scan'" class="space-y-6">
      <div v-if="depLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <template v-else>
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between gap-3 sm:items-center">
          <div class="flex items-center gap-3">
            <span class="material-symbols-rounded text-2xl" :class="depScanStatusClass">{{ depScanStatusIcon }}</span>
            <div>
              <span class="text-lg font-semibold" :class="depScanStatusClass">{{ depScanStatusText }}</span>
              <p class="text-xs text-surface-500">
                {{ depTotals.vulnerabilities }} total vulnerabilities across all scans
              </p>
            </div>
          </div>
          <button @click="fetchDepScans" class="btn-secondary" :disabled="depLoading">
            <span class="material-symbols-rounded">refresh</span>
            <span class="hidden sm:inline">Refresh</span>
          </button>
        </div>

        <!-- Severity Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
          <div class="stat-card text-center">
            <div class="stat-value" :class="depTotals.vulnerabilities > 0 ? 'text-surface-600 dark:text-surface-300' : 'text-green-500'">
              {{ depTotals.vulnerabilities }}
            </div>
            <div class="stat-label">Total</div>
          </div>
          <div class="stat-card text-center">
            <div class="stat-value" :class="depTotals.critical > 0 ? 'text-red-500' : ''">
              {{ depTotals.critical }}
            </div>
            <div class="stat-label">Critical</div>
          </div>
          <div class="stat-card text-center">
            <div class="stat-value" :class="depTotals.high > 0 ? 'text-orange-500' : ''">
              {{ depTotals.high }}
            </div>
            <div class="stat-label">High</div>
          </div>
          <div class="stat-card text-center">
            <div class="stat-value" :class="depTotals.medium > 0 ? 'text-amber-500' : ''">
              {{ depTotals.medium }}
            </div>
            <div class="stat-label">Medium</div>
          </div>
          <div class="stat-card text-center">
            <div class="stat-value" :class="depTotals.low > 0 ? 'text-blue-500' : ''">
              {{ depTotals.low }}
            </div>
            <div class="stat-label">Low</div>
          </div>
        </div>

        <!-- Latest Scans Per App -->
        <div class="card">
          <div class="card-header-responsive flex items-center gap-2">
            <span class="material-symbols-rounded text-surface-400">inventory</span>
            <span class="font-semibold">Latest Scan Results</span>
          </div>

          <div v-if="depScans.length === 0" class="p-8 text-center text-surface-400">
            <span class="material-symbols-rounded text-5xl mb-3 block">search_off</span>
            <p class="text-lg font-medium mb-1">No scan results yet</p>
            <p class="text-sm">The security-scan cron will populate results automatically.</p>
          </div>

          <div v-else class="divide-y divide-surface-100 dark:divide-surface-800">
            <div 
              v-for="scan in depScans" 
              :key="scan.id"
              class="p-4"
            >
              <!-- Scan Header Row -->
              <div 
                class="flex items-center justify-between cursor-pointer"
                @click="toggleDepScan(scan.id)"
              >
                <div class="flex items-center gap-3">
                  <span 
                    class="material-symbols-rounded text-xl"
                    :class="scan.critical_count > 0 ? 'text-red-500' : scan.high_count > 0 ? 'text-orange-500' : scan.medium_count > 0 ? 'text-amber-500' : 'text-green-500'"
                  >
                    {{ scan.critical_count > 0 ? 'error' : scan.vulnerabilities_found > 0 ? 'warning' : 'check_circle' }}
                  </span>
                  <div>
                    <span class="font-medium">{{ scan.source_app }}</span>
                    <span class="badge badge-neutral ml-2">{{ scan.scan_type }}</span>
                  </div>
                </div>
                
                <div class="flex items-center gap-4">
                  <div class="flex gap-2">
                    <span v-if="scan.critical_count > 0" class="badge badge-danger">{{ scan.critical_count }} critical</span>
                    <span v-if="scan.high_count > 0" class="badge badge-danger">{{ scan.high_count }} high</span>
                    <span v-if="scan.medium_count > 0" class="badge badge-warning">{{ scan.medium_count }} medium</span>
                    <span v-if="scan.low_count > 0" class="badge badge-info">{{ scan.low_count }} low</span>
                    <span v-if="scan.vulnerabilities_found === 0" class="badge badge-success">Clean</span>
                  </div>
                  <span class="text-xs text-surface-400">{{ formatScanDate(scan.scanned_at) }}</span>
                  <span 
                    class="material-symbols-rounded text-base text-surface-400 transition-transform" 
                    :class="depExpandedScan === scan.id && 'rotate-90'"
                  >
                    chevron_right
                  </span>
                </div>
              </div>

              <!-- Expanded Details -->
              <div v-if="depExpandedScan === scan.id && scan.results?.length" class="mt-4">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Package</th>
                      <th>Severity</th>
                      <th>Advisory</th>
                      <th>Affected Versions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(vuln, i) in scan.results" :key="i">
                      <td class="font-mono text-sm">{{ vuln.package || vuln.name || '-' }}</td>
                      <td>
                        <span :class="[
                          'badge',
                          vuln.severity === 'critical' ? 'badge-danger' :
                          vuln.severity === 'high' ? 'badge-danger' :
                          vuln.severity === 'moderate' || vuln.severity === 'medium' ? 'badge-warning' :
                          'badge-info'
                        ]">
                          {{ vuln.severity || 'unknown' }}
                        </span>
                      </td>
                      <td>
                        <a 
                          v-if="vuln.url" 
                          :href="vuln.url" 
                          target="_blank" 
                          class="text-primary-500 hover:underline text-sm"
                        >
                          {{ vuln.advisory || vuln.cve || 'View' }}
                        </a>
                        <span v-else class="text-sm">{{ vuln.advisory || vuln.title || '-' }}</span>
                      </td>
                      <td class="text-sm font-mono text-surface-500">{{ vuln.range || vuln.versions || '-' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-else-if="depExpandedScan === scan.id && (!scan.results || scan.results.length === 0)" class="mt-4 text-center py-4 text-surface-400 text-sm">
                No vulnerability details available
              </div>
            </div>
          </div>
        </div>

        <!-- Scan History -->
        <div v-if="depHistory.length" class="card">
          <div class="card-header-responsive flex items-center gap-2">
            <span class="material-symbols-rounded text-surface-400">history</span>
            <span class="font-semibold">Scan History</span>
          </div>
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>App</th>
                  <th>Type</th>
                  <th>Critical</th>
                  <th>High</th>
                  <th>Medium</th>
                  <th>Low</th>
                  <th>Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="h in depHistory" :key="h.id">
                  <td class="text-sm">{{ formatScanDate(h.scanned_at) }}</td>
                  <td><span class="badge badge-neutral">{{ h.source_app }}</span></td>
                  <td><span class="badge badge-neutral">{{ h.scan_type }}</span></td>
                  <td :class="h.critical_count > 0 ? 'text-red-500 font-semibold' : 'text-surface-400'">{{ h.critical_count }}</td>
                  <td :class="h.high_count > 0 ? 'text-orange-500 font-semibold' : 'text-surface-400'">{{ h.high_count }}</td>
                  <td :class="h.medium_count > 0 ? 'text-amber-500 font-semibold' : 'text-surface-400'">{{ h.medium_count }}</td>
                  <td :class="h.low_count > 0 ? 'text-blue-500 font-semibold' : 'text-surface-400'">{{ h.low_count }}</td>
                  <td class="font-semibold">{{ h.vulnerabilities_found }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Setup Info -->
        <div class="card p-4 border-blue-200 dark:border-blue-500/30 bg-blue-50/50 dark:bg-blue-500/5">
          <div class="flex items-start gap-3">
            <span class="material-symbols-rounded text-blue-500 text-xl mt-0.5">info</span>
            <div>
              <p class="text-sm font-medium text-blue-700 dark:text-blue-400 mb-1">Automated Dependency Scanning</p>
              <p class="text-sm text-blue-600/80 dark:text-blue-400/60">
                The <code class="px-1 py-0.5 rounded bg-blue-100 dark:bg-blue-500/20 text-xs font-mono">security-scan.sh</code> cron runs daily at 3 AM. 
                It scans PHP (composer audit) and Node.js (npm audit) dependencies across all apps and sends results here.
              </p>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- ============================================ -->
    <!-- Modals -->
    <!-- ============================================ -->

    <!-- Create Jail Modal -->
    <Modal :show="createJailModal" title="Create Fail2ban Jail" @close="createJailModal = false">
      <form @submit.prevent="createJail" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Jail Name</label>
          <input
            v-model="newJail.name"
            type="text"
            class="input"
            placeholder="my-custom-jail"
            required
          />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Port(s)</label>
            <input
              v-model="newJail.port"
              type="text"
              class="input"
              placeholder="http,https"
            />
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Filter</label>
            <input
              v-model="newJail.filter"
              type="text"
              class="input"
              placeholder="Leave empty to use jail name"
            />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Log Path</label>
          <input
            v-model="newJail.logpath"
            type="text"
            class="input font-mono"
            placeholder="/var/log/auth.log"
          />
        </div>

        <div class="grid grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Max Retry</label>
            <input
              v-model.number="newJail.maxretry"
              type="number"
              class="input"
              min="1"
            />
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Ban Time</label>
            <input
              v-model="newJail.bantime"
              type="text"
              class="input"
              placeholder="10m"
            />
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Find Time</label>
            <input
              v-model="newJail.findtime"
              type="text"
              class="input"
              placeholder="10m"
            />
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="createJailModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting || !newJail.name">
            <span v-if="submitting" class="spinner"></span>
            Create Jail
          </button>
        </div>
      </form>
    </Modal>

    <!-- Edit Jail Modal -->
    <Modal :show="editJailModal.show" title="Edit Fail2ban Jail" @close="editJailModal = { show: false, jail: null }">
      <form @submit.prevent="updateJail" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Jail Name</label>
          <input
            :value="editJail.name"
            type="text"
            class="input bg-surface-100 dark:bg-surface-800"
            disabled
          />
        </div>

        <div class="flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <span class="font-medium">Enabled</span>
          <Toggle v-model="editJail.enabled" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Port(s)</label>
            <input
              v-model="editJail.port"
              type="text"
              class="input"
              placeholder="http,https"
            />
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Filter</label>
            <input
              v-model="editJail.filter"
              type="text"
              class="input"
              placeholder="Filter name"
            />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Log Path</label>
          <input
            v-model="editJail.logpath"
            type="text"
            class="input font-mono"
            placeholder="/var/log/auth.log"
          />
        </div>

        <div class="grid grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Max Retry</label>
            <input
              v-model.number="editJail.maxretry"
              type="number"
              class="input"
              min="1"
            />
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Ban Time</label>
            <input
              v-model="editJail.bantime"
              type="text"
              class="input"
              placeholder="10m, 1h, 1d"
            />
            <p class="text-xs text-surface-500 mt-1">e.g. 10m, 1h, 1d</p>
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Find Time</label>
            <input
              v-model="editJail.findtime"
              type="text"
              class="input"
              placeholder="10m"
            />
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="editJailModal = { show: false, jail: null }" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Save Changes
          </button>
        </div>
      </form>
    </Modal>

    <!-- Delete Jail Modal -->
    <ConfirmModal
      :show="deleteJailModal.show"
      title="Delete Jail"
      :message="`Are you sure you want to delete the jail '${deleteJailModal.jail?.name}'? This action cannot be undone.`"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      require-confirmation="DELETE"
      @confirm="deleteJail"
      @cancel="deleteJailModal = { show: false, jail: null }"
    />

    <!-- Ban IP Modal -->
    <Modal :show="banIpModal.show" title="Ban IP Address" size="sm" @close="banIpModal = { show: false, ip: '' }">
      <form @submit.prevent="banIp" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">IP Address</label>
          <input
            v-model="banIpModal.ip"
            type="text"
            class="input font-mono"
            placeholder="192.168.1.100"
            required
          />
        </div>
        <p class="text-sm text-surface-500">
          This IP will be banned in the <strong>{{ selectedJail?.name }}</strong> jail.
        </p>
        <div class="flex justify-end gap-3 pt-2">
          <button type="button" @click="banIpModal = { show: false, ip: '' }" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting || !banIpModal.ip">
            <span v-if="submitting" class="spinner"></span>
            Ban IP
          </button>
        </div>
      </form>
    </Modal>

    <!-- Unban IP Modal -->
    <Modal :show="unbanModal.show" title="Unban IP" size="sm" @close="unbanModal = { show: false, ip: '' }">
      <p class="text-surface-600 dark:text-surface-400 mb-4">
        Are you sure you want to unban <code class="font-mono bg-surface-100 dark:bg-surface-800 px-2 py-0.5 rounded">{{ unbanModal.ip }}</code>?
      </p>
      <div class="flex justify-end gap-3">
        <button @click="unbanModal = { show: false, ip: '' }" class="btn-secondary">Cancel</button>
        <button @click="unbanIp" class="btn-primary" :disabled="submitting">
          <span v-if="submitting" class="spinner"></span>
          Unban
        </button>
      </div>
    </Modal>

    <!-- Add Port Modal -->
    <Modal :show="addPortModal" title="Add Port" size="sm" @close="addPortModal = false">
      <form @submit.prevent="addPort" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Port Number</label>
          <input
            v-model="newPort.port"
            type="number"
            class="input"
            placeholder="8080"
            min="1"
            max="65535"
            required
          />
        </div>
        <div>
          <label class="block text-sm font-medium mb-2">Protocol</label>
          <select v-model="newPort.protocol" class="input">
            <option value="tcp">TCP</option>
            <option value="udp">UDP</option>
          </select>
        </div>
        <div class="flex justify-end gap-3 pt-2">
          <button type="button" @click="addPortModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting || !newPort.port">
            <span v-if="submitting" class="spinner"></span>
            Add Port
          </button>
        </div>
      </form>
    </Modal>

    <!-- Remove Port Modal -->
    <ConfirmModal
      :show="removePortModal.show"
      title="Remove Port"
      :message="`Are you sure you want to remove port ${removePortModal.port}?`"
      confirm-text="Remove"
      :danger="true"
      :loading="submitting"
      @confirm="removePort"
      @cancel="removePortModal = { show: false, port: null }"
    />

    <!-- Add Service Modal -->
    <Modal :show="addServiceModal" title="Add Service" size="sm" @close="addServiceModal = false">
      <form @submit.prevent="addService" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Service</label>
          <select v-model="newService" class="input" required>
            <option value="">Select a service...</option>
            <option v-for="service in availableServices" :key="service" :value="service">
              {{ service }}
            </option>
          </select>
        </div>
        <p class="text-sm text-surface-500">
          Or enter a custom service name:
        </p>
        <input
          v-model="newService"
          type="text"
          class="input"
          placeholder="custom-service"
        />
        <div class="flex justify-end gap-3 pt-2">
          <button type="button" @click="addServiceModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting || !newService">
            <span v-if="submitting" class="spinner"></span>
            Add Service
          </button>
        </div>
      </form>
    </Modal>

    <!-- Remove Service Modal -->
    <ConfirmModal
      :show="removeServiceModal.show"
      title="Remove Service"
      :message="`Are you sure you want to remove the service '${removeServiceModal.service}'?`"
      confirm-text="Remove"
      :danger="true"
      :loading="submitting"
      @confirm="removeService"
      @cancel="removeServiceModal = { show: false, service: null }"
    />

    <!-- ============================================ -->
    <!-- CPGuard Modals -->
    <!-- ============================================ -->

    <!-- Install CPGuard Modal -->
    <Modal :show="cpguardInstallModal" title="Install CPGuard" @close="cpguardInstallModal = false">
      <form @submit.prevent="installCpguard" class="space-y-4">
        <div class="p-4 bg-amber-50 dark:bg-amber-500/10 rounded-xl">
          <p class="text-sm text-amber-700 dark:text-amber-400">
            <span class="material-symbols-rounded align-middle mr-1">info</span>
            You need a valid CPGuard license key to install. Get one from 
            <a href="https://opsshield.com" target="_blank" class="underline">opsshield.com</a>
          </p>
        </div>
        
        <div>
          <label class="block text-sm font-medium mb-2">License Key</label>
          <input
            v-model="cpguardInstallForm.license_key"
            type="text"
            class="input font-mono"
            placeholder="Enter your CPGuard license key"
            required
          />
        </div>
        
        <p class="text-sm text-surface-500">
          Installation may take a few minutes. The service will be configured automatically.
        </p>
        
        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="cpguardInstallModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting || !cpguardInstallForm.license_key">
            <span v-if="submitting" class="spinner"></span>
            Install CPGuard
          </button>
        </div>
      </form>
    </Modal>

    <!-- Update License Modal -->
    <Modal :show="cpguardLicenseModal" title="Update License Key" @close="cpguardLicenseModal = false">
      <form @submit.prevent="updateCpguardLicense" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Current License</label>
          <input
            :value="cpguardLicense?.license_key || 'Unknown'"
            type="text"
            class="input font-mono bg-surface-100 dark:bg-surface-800"
            disabled
          />
        </div>
        
        <div>
          <label class="block text-sm font-medium mb-2">New License Key</label>
          <input
            v-model="cpguardLicenseForm.license_key"
            type="text"
            class="input font-mono"
            placeholder="Enter new license key"
            required
          />
        </div>
        
        <p class="text-sm text-surface-500">
          Use this to renew or change your CPGuard license. The service will be restarted automatically.
        </p>
        
        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="cpguardLicenseModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting || !cpguardLicenseForm.license_key">
            <span v-if="submitting" class="spinner"></span>
            Update License
          </button>
        </div>
      </form>
    </Modal>

    <!-- Add Whitelist IP Modal -->
    <Modal :show="cpguardAddWhitelistIpModal" title="Add IP to Whitelist" size="sm" @close="cpguardAddWhitelistIpModal = false">
      <form @submit.prevent="addCpguardWhitelistIp" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">IP Address</label>
          <input
            v-model="cpguardWhitelistIpForm.ip"
            type="text"
            class="input font-mono"
            placeholder="192.168.1.100 or 10.0.0.0/8"
            required
          />
          <p class="text-xs text-surface-500 mt-1">You can use CIDR notation for IP ranges</p>
        </div>
        
        <div class="flex justify-end gap-3 pt-2">
          <button type="button" @click="cpguardAddWhitelistIpModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting || !cpguardWhitelistIpForm.ip">
            <span v-if="submitting" class="spinner"></span>
            Add to Whitelist
          </button>
        </div>
      </form>
    </Modal>

    <!-- Add Whitelist Domain Modal -->
    <Modal :show="cpguardAddWhitelistDomainModal" title="Add Domain to Whitelist" size="sm" @close="cpguardAddWhitelistDomainModal = false">
      <form @submit.prevent="addCpguardWhitelistDomain" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Domain</label>
          <input
            v-model="cpguardWhitelistDomainForm.domain"
            type="text"
            class="input font-mono"
            placeholder="example.com or *.example.com"
            required
          />
          <p class="text-xs text-surface-500 mt-1">Use * for wildcards (e.g., *.example.com)</p>
        </div>
        
        <div class="flex justify-end gap-3 pt-2">
          <button type="button" @click="cpguardAddWhitelistDomainModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting || !cpguardWhitelistDomainForm.domain">
            <span v-if="submitting" class="spinner"></span>
            Add to Whitelist
          </button>
        </div>
      </form>
    </Modal>

    <!-- Add Blacklist IP Modal -->
    <Modal :show="cpguardAddBlacklistIpModal" title="Add IP to Blacklist" size="sm" @close="cpguardAddBlacklistIpModal = false">
      <form @submit.prevent="addCpguardBlacklistIp" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">IP Address</label>
          <input
            v-model="cpguardBlacklistIpForm.ip"
            type="text"
            class="input font-mono"
            placeholder="192.168.1.100 or 10.0.0.0/8"
            required
          />
          <p class="text-xs text-surface-500 mt-1">This IP will be blocked from accessing the server</p>
        </div>
        
        <div class="flex justify-end gap-3 pt-2">
          <button type="button" @click="cpguardAddBlacklistIpModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting || !cpguardBlacklistIpForm.ip">
            <span v-if="submitting" class="spinner"></span>
            Add to Blacklist
          </button>
        </div>
      </form>
    </Modal>

    <!-- Remove List Entry Modal -->
    <ConfirmModal
      :show="cpguardRemoveModal.show"
      title="Remove Entry"
      :message="`Are you sure you want to remove '${cpguardRemoveModal.value}' from the ${cpguardRemoveModal.listType}?`"
      confirm-text="Remove"
      :danger="true"
      :loading="submitting"
      @confirm="removeCpguardListEntry"
      @cancel="cpguardRemoveModal = { show: false, type: '', listType: '', value: '' }"
    />

    <!-- Trigger Scan Modal -->
    <Modal :show="cpguardScanModal" title="Trigger Malware Scan" @close="cpguardScanModal = false">
      <form @submit.prevent="triggerCpguardScan" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Scan Path</label>
          <input
            v-model="cpguardScanForm.path"
            type="text"
            class="input font-mono"
            placeholder="/home"
          />
          <p class="text-xs text-surface-500 mt-1">Directory to scan for malware</p>
        </div>
        
        <div class="flex items-center justify-between p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <div>
            <span class="font-medium">Run in Background</span>
            <p class="text-xs text-surface-500">Scan will run asynchronously</p>
          </div>
          <Toggle v-model="cpguardScanForm.background" />
        </div>
        
        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="cpguardScanModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Start Scan
          </button>
        </div>
      </form>
    </Modal>
  </div>
</template>
