<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import NasTroubleshootingPanel from '@/components/NasTroubleshootingPanel.vue'
import NasHealthWidget from '@/components/NasHealthWidget.vue'
import NasSetupWizard from '@/components/NasSetupWizard.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

// Tab management
const activeTab = ref('storage')

// Storage state
const loading = ref(true)
const connections = ref([])
const domains = ref([])
const domainOverrides = ref([])
const setupWizardModal = ref(false)
const editModal = ref({ show: false, connection: null })
const deleteModal = ref({ show: false, connection: null })
const assignModal = ref({ show: false, connection: null })
const statsModal = ref({ show: false, connection: null, stats: null, loading: false })
const submitting = ref(false)
const testing = ref(null)
const mounting = ref(null)

// VPN state
const vpnLoading = ref(true)
const vpnConnections = ref([])
const vpnCreateModal = ref(false)
const vpnDeleteModal = ref({ show: false, vpn: null })
const vpnLogsModal = ref({ show: false, vpn: null, logs: '', loading: false })
const vpnAction = ref(null) // name of VPN being acted upon
const vpnInfoExpanded = ref(true)
const vpnCreateMode = ref('guided') // 'guided' or 'advanced'
const vpnWizardStep = ref(1)
const vpnWizardSteps = [
  { num: 1, title: 'Basic Info', icon: 'badge' },
  { num: 2, title: 'Connection', icon: 'dns' },
  { num: 3, title: 'Security', icon: 'security' },
  { num: 4, title: 'Certificates', icon: 'verified_user' },
  { num: 5, title: 'Routes', icon: 'route' },
]

const newVpn = ref({
  name: '',
  description: '',
  config_content: '',
  up_script: '',
  down_script: '',
  notes: '',
  // Guided form fields
  server: '',
  port: 1194,
  protocol: 'udp',
  device: 'tun',
  cipher: 'AES-256-GCM',
  auth: 'SHA256',
  compression: 'none',
  ca_cert: '',
  client_cert: '',
  client_key: '',
  tls_auth: '',
  tls_direction: '',
  username: '',
  password: '',
  routes: []
})

// Watch route query for tab
watch(() => route.query.tab, (tab) => {
  activeTab.value = tab === 'vpn' ? 'vpn' : 'storage'
}, { immediate: true })

const setTab = (tab) => {
  activeTab.value = tab
  router.replace({ query: { ...route.query, tab } })
}

const vpnStats = computed(() => ({
  total: vpnConnections.value.length,
  connected: vpnConnections.value.filter(v => v.status === 'connected').length,
  disconnected: vpnConnections.value.filter(v => v.status === 'disconnected').length,
  error: vpnConnections.value.filter(v => v.status === 'error').length
}))

// ==================== Storage Functions ====================

const fetchConnections = async () => {
  try {
    const response = await api.get('/nas')
    if (response.data.success) {
      connections.value = response.data.data.connections || []
    }
  } catch (e) {
    toast.error('Failed to load NAS connections')
  } finally {
    loading.value = false
  }
}

const fetchDomainOverrides = async () => {
  try {
    const response = await api.get('/nas/domains')
    if (response.data.success) {
      domainOverrides.value = response.data.data.overrides || []
    }
  } catch (e) {
    // Silently fail
  }
}

const fetchDomains = async () => {
  try {
    const response = await api.get('/sites')
    if (response.data.success) {
      domains.value = response.data.data.vhosts || []
    }
  } catch (e) {
    // Silently fail
  }
}

const updateConnection = async () => {
  if (!editModal.value.connection) return
  
  submitting.value = true
  try {
    const payload = { ...editModal.value.connection }
    // Map vpn_name to vpn_config_path
    if (payload.vpn_name) {
      payload.vpn_config_path = `/etc/openvpn/client/${payload.vpn_name}.conf`
    }
    delete payload.vpn_name
    delete payload.id
    delete payload.created_at
    delete payload.updated_at
    delete payload.domain_count
    delete payload.domain_overrides
    delete payload.last_check
    
    const response = await api.put(`/nas/${editModal.value.connection.id}`, payload)
    if (response.data.success) {
      toast.success('NAS connection updated successfully')
      editModal.value = { show: false, connection: null }
      await fetchConnections()
    } else {
      toast.error(response.data.error || 'Failed to update connection')
    }
  } catch (e) {
    toast.error(e.message || 'Failed to update connection')
  } finally {
    submitting.value = false
  }
}

const deleteConnection = async () => {
  if (!deleteModal.value.connection) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/nas/${deleteModal.value.connection.id}`)
    if (response.data.success) {
      toast.success('NAS connection deleted successfully')
      deleteModal.value = { show: false, connection: null }
      await fetchConnections()
    } else {
      toast.error(response.data.error || 'Failed to delete connection')
    }
  } catch (e) {
    toast.error(e.message || 'Failed to delete connection')
  } finally {
    submitting.value = false
  }
}

const testConnection = async (connection) => {
  testing.value = connection.id
  try {
    const response = await api.post(`/nas/${connection.id}/test`)
    if (response.data.success) {
      toast.success('Connection test successful')
      await fetchConnections()
    } else {
      toast.error(response.data.error || 'Connection test failed')
      await fetchConnections()
    }
  } catch (e) {
    toast.error(e.message || 'Connection test failed')
    await fetchConnections()
  } finally {
    testing.value = null
  }
}

const mountConnection = async (connection) => {
  mounting.value = connection.id
  try {
    const response = await api.post(`/nas/${connection.id}/mount`)
    if (response.data.success) {
      toast.success(response.data.message || 'NFS share mounted successfully')
      await fetchConnections()
    } else {
      toast.error(response.data.error || 'Mount failed')
    }
  } catch (e) {
    toast.error(e.message || 'Mount failed')
  } finally {
    mounting.value = null
  }
}

const setDefault = async (connection) => {
  try {
    const response = await api.post(`/nas/${connection.id}/default`)
    if (response.data.success) {
      toast.success('Default storage updated')
      await fetchConnections()
    } else {
      toast.error(response.data.error || 'Failed to set default')
    }
  } catch (e) {
    toast.error(e.message || 'Failed to set default')
  }
}

const openStatsModal = async (connection) => {
  statsModal.value = { show: true, connection, stats: null, loading: true }
  try {
    const response = await api.get(`/nas/${connection.id}/stats`)
    if (response.data.success) {
      statsModal.value.stats = response.data.data
    } else {
      toast.error(response.data.error || 'Failed to load stats')
    }
  } catch (e) {
    toast.error(e.message || 'Failed to load stats')
  } finally {
    statsModal.value.loading = false
  }
}

const openAssignModal = async (connection) => {
  assignModal.value = { 
    show: true, 
    connection,
    selectedDomain: '',
    subPath: ''
  }
}

const assignDomain = async () => {
  if (!assignModal.value.connection || !assignModal.value.selectedDomain) return
  
  submitting.value = true
  try {
    const response = await api.post(`/nas/${assignModal.value.connection.id}/domains`, {
      domain: assignModal.value.selectedDomain,
      sub_path: assignModal.value.subPath || null
    })
    if (response.data.success) {
      toast.success('Domain assigned successfully')
      assignModal.value = { show: false, connection: null }
      await fetchConnections()
      await fetchDomainOverrides()
    } else {
      toast.error(response.data.error || 'Failed to assign domain')
    }
  } catch (e) {
    toast.error(e.message || 'Failed to assign domain')
  } finally {
    submitting.value = false
  }
}

const removeDomainOverride = async (domain) => {
  try {
    const response = await api.delete(`/nas/domains/${domain}`)
    if (response.data.success) {
      toast.success('Domain override removed')
      await fetchConnections()
      await fetchDomainOverrides()
    } else {
      toast.error(response.data.error || 'Failed to remove override')
    }
  } catch (e) {
    toast.error(e.message || 'Failed to remove override')
  }
}

const openEditModal = async (connection) => {
  // Extract VPN name from path
  let vpnName = ''
  if (connection.vpn_config_path) {
    const match = connection.vpn_config_path.match(/\/etc\/openvpn\/client\/(.+)\.conf$/)
    if (match) vpnName = match[1]
  }
  
  editModal.value = {
    show: true,
    connection: { ...connection, vpn_name: vpnName }
  }
}

// ==================== VPN Functions ====================

const fetchVpnConnections = async () => {
  vpnLoading.value = true
  try {
    const response = await api.get('/vpn')
    if (response.data.success) {
      vpnConnections.value = response.data.data.connections || []
    }
  } catch (e) {
    toast.error('Failed to load VPN connections')
  } finally {
    vpnLoading.value = false
  }
}

const createVpn = async () => {
  submitting.value = true
  try {
    const response = await api.post('/vpn', newVpn.value)
    if (response.data.success) {
      toast.success('VPN connection created successfully')
      vpnCreateModal.value = false
      resetNewVpn()
      await fetchVpnConnections()
    } else {
      toast.error(response.data.error || 'Failed to create VPN')
    }
  } catch (e) {
    toast.error(e.message || 'Failed to create VPN')
  } finally {
    submitting.value = false
  }
}

const deleteVpn = async () => {
  if (!vpnDeleteModal.value.vpn) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/vpn/${vpnDeleteModal.value.vpn.name}`)
    if (response.data.success) {
      toast.success('VPN connection deleted successfully')
      vpnDeleteModal.value = { show: false, vpn: null }
      await fetchVpnConnections()
    } else {
      toast.error(response.data.error || 'Failed to delete VPN')
    }
  } catch (e) {
    toast.error(e.message || 'Failed to delete VPN')
  } finally {
    submitting.value = false
  }
}

const startVpn = async (vpn) => {
  vpnAction.value = vpn.name
  try {
    const response = await api.post(`/vpn/${vpn.name}/start`)
    if (response.data.success) {
      toast.success(`VPN ${vpn.name} started`)
      await fetchVpnConnections()
    } else {
      toast.error(response.data.error || 'Failed to start VPN')
    }
  } catch (e) {
    toast.error(e.message || 'Failed to start VPN')
  } finally {
    vpnAction.value = null
  }
}

const stopVpn = async (vpn) => {
  vpnAction.value = vpn.name
  try {
    const response = await api.post(`/vpn/${vpn.name}/stop`)
    if (response.data.success) {
      toast.success(`VPN ${vpn.name} stopped`)
      await fetchVpnConnections()
    } else {
      toast.error(response.data.error || 'Failed to stop VPN')
    }
  } catch (e) {
    toast.error(e.message || 'Failed to stop VPN')
  } finally {
    vpnAction.value = null
  }
}

const restartVpn = async (vpn) => {
  vpnAction.value = vpn.name
  try {
    const response = await api.post(`/vpn/${vpn.name}/restart`)
    if (response.data.success) {
      toast.success(`VPN ${vpn.name} restarted`)
      await fetchVpnConnections()
    } else {
      toast.error(response.data.error || 'Failed to restart VPN')
    }
  } catch (e) {
    toast.error(e.message || 'Failed to restart VPN')
  } finally {
    vpnAction.value = null
  }
}

const openVpnLogs = async (vpn) => {
  vpnLogsModal.value = { show: true, vpn, logs: '', loading: true }
  try {
    const response = await api.get(`/vpn/${vpn.name}/logs?lines=100`)
    if (response.data.success) {
      vpnLogsModal.value.logs = response.data.data.logs || 'No logs available'
    } else {
      vpnLogsModal.value.logs = 'Failed to load logs'
    }
  } catch (e) {
    vpnLogsModal.value.logs = 'Failed to load logs'
  } finally {
    vpnLogsModal.value.loading = false
  }
}

// Re-import a changed .ovpn for an existing VPN (PUT /vpn/{name})
const vpnReimportInput = ref(null)
const vpnReimportTarget = ref(null)

const triggerVpnReimport = (vpn) => {
  vpnReimportTarget.value = vpn
  vpnReimportInput.value?.click()
}

const handleVpnReimport = async (event) => {
  const file = event.target.files[0]
  const target = vpnReimportTarget.value
  event.target.value = ''
  if (!file || !target) return

  const content = await file.text()
  vpnAction.value = target.name
  try {
    const response = await api.put(`/vpn/${target.name}`, { config_content: content })
    if (response.data.success) {
      toast.success(response.data.message || `VPN ${target.name} config updated`)
      await fetchVpnConnections()
    } else {
      toast.error(response.data.error || 'Failed to update VPN config')
    }
  } catch (e) {
    toast.error(e.message || 'Failed to update VPN config')
  } finally {
    vpnAction.value = null
    vpnReimportTarget.value = null
  }
}

const resetNewVpn = () => {
  newVpn.value = {
    name: '',
    description: '',
    config_content: '',
    up_script: '',
    down_script: '',
    notes: '',
    server: '',
    port: 1194,
    protocol: 'udp',
    device: 'tun',
    cipher: 'AES-256-GCM',
    auth: 'SHA256',
    compression: 'none',
    ca_cert: '',
    client_cert: '',
    client_key: '',
    tls_auth: '',
    tls_direction: '',
    username: '',
    password: '',
    routes: []
  }
  vpnCreateMode.value = 'guided'
  vpnWizardStep.value = 1
}

// Wizard navigation
const canGoNext = computed(() => {
  const v = newVpn.value
  switch (vpnWizardStep.value) {
    case 1: return v.name.trim().length > 0
    case 2: return v.server.trim().length > 0
    case 3: return true
    case 4: return v.ca_cert.trim().length > 0
    case 5: return true
    default: return true
  }
})

const nextStep = () => {
  if (vpnWizardStep.value < 5 && canGoNext.value) {
    vpnWizardStep.value++
  }
}

const prevStep = () => {
  if (vpnWizardStep.value > 1) {
    vpnWizardStep.value--
  }
}

// File upload handler for certificates
const handleCertUpload = (event, field) => {
  const file = event.target.files[0]
  if (!file) return
  
  const reader = new FileReader()
  reader.onload = (e) => {
    newVpn.value[field] = e.target.result
  }
  reader.readAsText(file)
}

// Import .ovpn file and parse all fields
const handleOvpnImport = (event) => {
  const file = event.target.files[0]
  if (!file) return
  
  const reader = new FileReader()
  reader.onload = (e) => {
    const content = e.target.result
    parseOvpnFile(content)
    toast.success('Configuration imported successfully')
  }
  reader.readAsText(file)
  // Reset input so same file can be selected again
  event.target.value = ''
}

const parseOvpnFile = (content) => {
  const v = newVpn.value
  
  // Extract remote server and port
  const remoteMatch = content.match(/^remote\s+(\S+)\s+(\d+)/m)
  if (remoteMatch) {
    v.server = remoteMatch[1]
    v.port = parseInt(remoteMatch[2])
  }
  
  // Extract protocol
  const protoMatch = content.match(/^proto\s+(udp|tcp)/m)
  if (protoMatch) {
    v.protocol = protoMatch[1]
  }
  
  // Extract device type
  const devMatch = content.match(/^dev\s+(tun|tap)/m)
  if (devMatch) {
    v.device = devMatch[1]
  }
  
  // Extract cipher
  const cipherMatch = content.match(/^cipher\s+(\S+)/m)
  if (cipherMatch) {
    v.cipher = cipherMatch[1]
  }
  
  // Extract auth
  const authMatch = content.match(/^auth\s+(\S+)/m)
  if (authMatch) {
    v.auth = authMatch[1]
  }
  
  // Extract compression
  if (content.match(/^comp-lzo/m)) {
    v.compression = 'lzo'
  } else if (content.match(/^compress\s+lz4-v2/m)) {
    v.compression = 'lz4-v2'
  } else if (content.match(/^compress\s+lz4/m)) {
    v.compression = 'lz4'
  }
  
  // Extract key-direction
  const keyDirMatch = content.match(/^key-direction\s+(\d)/m)
  if (keyDirMatch) {
    v.tls_direction = keyDirMatch[1]
  }
  
  // Extract inline certificates
  const extractBlock = (tag) => {
    const regex = new RegExp(`<${tag}>([\\s\\S]*?)<\\/${tag}>`, 'm')
    const match = content.match(regex)
    return match ? match[1].trim() : ''
  }
  
  v.ca_cert = extractBlock('ca')
  v.client_cert = extractBlock('cert')
  v.client_key = extractBlock('key')
  v.tls_auth = extractBlock('tls-auth') || extractBlock('tls-crypt')
  
  // Store full config for advanced mode
  v.config_content = content
}

// Add/remove route
const addRoute = () => {
  newVpn.value.routes.push({ network: '', gateway: '' })
}

const removeRoute = (index) => {
  newVpn.value.routes.splice(index, 1)
}

// Generate OpenVPN config from guided form
const generateVpnConfig = () => {
  const v = newVpn.value
  let config = `# OpenVPN Client Configuration
# Generated by VPS Admin Panel
client
dev ${v.device}
proto ${v.protocol}
remote ${v.server} ${v.port}
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
cipher ${v.cipher}
auth ${v.auth}
verb 3`

  // Compression
  if (v.compression === 'lzo') {
    config += '\ncomp-lzo'
  } else if (v.compression === 'lz4') {
    config += '\ncompress lz4'
  } else if (v.compression === 'lz4-v2') {
    config += '\ncompress lz4-v2'
  }

  // Auth user/pass
  if (v.username && v.password) {
    config += '\nauth-user-pass'
  }

  // Routes - add script-security and up/down scripts if routes defined
  if (v.routes.length > 0) {
    config += '\nscript-security 2'
    config += `\nup /etc/openvpn/client/${v.name}-up.sh`
    config += `\ndown /etc/openvpn/client/${v.name}-down.sh`
  }

  // CA Certificate
  if (v.ca_cert) {
    config += `\n<ca>\n${v.ca_cert.trim()}\n</ca>`
  }

  // Client Certificate
  if (v.client_cert) {
    config += `\n<cert>\n${v.client_cert.trim()}\n</cert>`
  }

  // Client Key
  if (v.client_key) {
    config += `\n<key>\n${v.client_key.trim()}\n</key>`
  }

  // TLS Auth
  if (v.tls_auth) {
    const direction = v.tls_direction ? ` ${v.tls_direction}` : ''
    config += `\nkey-direction${direction}`
    config += `\n<tls-auth>\n${v.tls_auth.trim()}\n</tls-auth>`
  }

  return config
}

// Generate up/down scripts for routes
const generateRouteScripts = () => {
  const v = newVpn.value
  if (v.routes.length === 0) return { up: '', down: '' }

  let upScript = '#!/bin/bash\n# Auto-generated route script\n'
  let downScript = '#!/bin/bash\n# Auto-generated route script\n'

  v.routes.forEach(route => {
    if (route.network) {
      const gateway = route.gateway || '$route_vpn_gateway'
      upScript += `ip route add ${route.network} via ${gateway} dev $1 2>/dev/null || true\n`
      downScript += `ip route del ${route.network} 2>/dev/null || true\n`
    }
  })

  return { up: upScript, down: downScript }
}

// Submit VPN - handles both guided and advanced modes
const submitVpn = async () => {
  if (vpnCreateMode.value === 'guided') {
    // Validate required fields
    if (!newVpn.value.server) {
      toast.error('Server address is required')
      return
    }
    if (!newVpn.value.ca_cert) {
      toast.error('CA Certificate is required')
      return
    }

    // Generate config from form
    newVpn.value.config_content = generateVpnConfig()

    // Generate route scripts
    const scripts = generateRouteScripts()
    if (scripts.up) newVpn.value.up_script = scripts.up
    if (scripts.down) newVpn.value.down_script = scripts.down
  }

  await createVpn()
}

// ==================== Helpers ====================

const formatDate = (date) => {
  if (!date) return 'Never'
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const getStatusColor = (status) => {
  switch (status) {
    case 'active': 
    case 'connected': return 'success'
    case 'error': return 'danger'
    case 'inactive':
    case 'disconnected': return 'warning'
    case 'connecting': return 'info'
    default: return 'default'
  }
}

const getDriverIcon = (driver) => {
  switch (driver) {
    case 'nfs': return 'cloud_sync'
    case 'cifs': return 'folder_shared'
    case 'local': return 'hard_drive'
    default: return 'storage'
  }
}

const getVpnStatusIcon = (status) => {
  switch (status) {
    case 'connected': return 'vpn_lock'
    case 'connecting': return 'sync'
    case 'error': return 'error'
    default: return 'vpn_key_off'
  }
}

const availableDomains = computed(() => {
  const assignedDomains = domainOverrides.value.map(o => o.domain)
  return domains.value.filter(d => !assignedDomains.includes(d.domain))
})

onMounted(() => {
  fetchConnections()
  fetchDomains()
  fetchDomainOverrides()
  fetchVpnConnections()
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">NAS Storage</h1>
        <p class="text-surface-500 text-sm mt-1">Manage storage connections and VPN tunnels</p>
      </div>
      <button 
        v-if="activeTab === 'storage'"
        @click="setupWizardModal = true" 
        class="btn-primary"
      >
        <span class="material-symbols-rounded">auto_fix_high</span>
        Connect NAS
      </button>
      <button 
        v-else
        @click="vpnCreateModal = true" 
        class="btn-primary"
      >
        <span class="material-symbols-rounded">add</span>
        New VPN
      </button>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 p-1 bg-surface-100 dark:bg-surface-800 rounded-xl mb-6 w-fit">
      <button
        @click="setTab('storage')"
        :class="[
          'px-4 py-2 rounded-lg text-sm font-medium transition-all',
          activeTab === 'storage' 
            ? 'bg-white dark:bg-surface-700 shadow text-primary-600 dark:text-primary-400' 
            : 'text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-200'
        ]"
      >
        <span class="material-symbols-rounded text-lg align-middle mr-1">hard_drive</span>
        Storage Connections
      </button>
      <button
        @click="setTab('vpn')"
        :class="[
          'px-4 py-2 rounded-lg text-sm font-medium transition-all',
          activeTab === 'vpn' 
            ? 'bg-white dark:bg-surface-700 shadow text-primary-600 dark:text-primary-400' 
            : 'text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-200'
        ]"
      >
        <span class="material-symbols-rounded text-lg align-middle mr-1">vpn_lock</span>
        VPN Connections
      </button>
    </div>

    <!-- ==================== STORAGE TAB ==================== -->
    <div v-if="activeTab === 'storage'">
      <!-- Health Monitor -->
      <NasHealthWidget />

      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <!-- Connections Grid -->
      <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <div 
          v-for="conn in connections" 
          :key="conn.id"
          class="card overflow-hidden"
        >
          <div class="p-4">
            <!-- Header -->
            <div class="flex items-start justify-between mb-4">
              <div class="flex items-center gap-3">
                <div 
                  class="w-12 h-12 rounded-xl flex items-center justify-center"
                  :class="conn.is_default ? 'bg-primary-100 dark:bg-primary-500/20' : 'bg-surface-100 dark:bg-surface-700'"
                >
                  <span 
                    class="material-symbols-rounded text-2xl"
                    :class="conn.is_default ? 'text-primary-600 dark:text-primary-400' : 'text-surface-500'"
                  >
                    {{ getDriverIcon(conn.driver) }}
                  </span>
                </div>
                <div>
                  <div class="flex items-center gap-2">
                    <h3 class="font-semibold">{{ conn.name }}</h3>
                    <span v-if="conn.is_default" class="badge badge-primary text-xs">Default</span>
                  </div>
                  <p class="text-sm text-surface-500 uppercase">{{ conn.driver }}</p>
                </div>
              </div>
              <StatusBadge :status="getStatusColor(conn.status)" :label="conn.status" />
            </div>

            <!-- Details -->
            <div class="space-y-2 text-sm mb-4">
              <div class="flex items-center gap-2 text-surface-600 dark:text-surface-400">
                <span class="material-symbols-rounded text-lg">folder</span>
                <span class="truncate">{{ conn.mount_point }}</span>
              </div>
              
              <div v-if="conn.driver === 'nfs' && conn.nfs_server" class="flex items-center gap-2 text-surface-600 dark:text-surface-400">
                <span class="material-symbols-rounded text-lg">dns</span>
                <span class="truncate">{{ conn.nfs_server }}:{{ conn.nfs_path }}</span>
              </div>

              <div v-if="conn.vpn_enabled" class="flex items-center gap-2 text-blue-600 dark:text-blue-400">
                <span class="material-symbols-rounded text-lg">vpn_lock</span>
                <span>VPN Enabled</span>
              </div>

              <div class="flex items-center gap-2 text-surface-500">
                <span class="material-symbols-rounded text-lg">language</span>
                <span>{{ conn.domain_count || 0 }} domain override(s)</span>
              </div>

              <div v-if="conn.last_check" class="flex items-center gap-2 text-surface-500">
                <span class="material-symbols-rounded text-lg">schedule</span>
                <span>Checked: {{ formatDate(conn.last_check) }}</span>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex flex-wrap gap-2">
              <button 
                @click="testConnection(conn)"
                class="btn-sm btn-secondary flex-1"
                :disabled="testing === conn.id"
              >
                <span v-if="testing === conn.id" class="spinner-sm"></span>
                <span v-else class="material-symbols-rounded text-lg">speed</span>
                Test
              </button>
              <button 
                v-if="conn.driver === 'nfs'"
                @click="mountConnection(conn)"
                class="btn-sm flex-1"
                :class="conn.status === 'error' ? 'btn-warning' : 'btn-secondary'"
                :disabled="mounting === conn.id"
                title="Remount the NFS share (use if disconnected)"
              >
                <span v-if="mounting === conn.id" class="spinner-sm"></span>
                <span v-else class="material-symbols-rounded text-lg">install_desktop</span>
                Reconnect
              </button>
              <button 
                @click="openStatsModal(conn)"
                class="btn-sm btn-secondary flex-1"
              >
                <span class="material-symbols-rounded text-lg">analytics</span>
                Stats
              </button>
              <button 
                @click="openAssignModal(conn)"
                class="btn-sm btn-secondary flex-1"
              >
                <span class="material-symbols-rounded text-lg">link</span>
                Assign
              </button>
            </div>
          </div>

          <!-- Footer Actions -->
          <div class="px-4 py-3 bg-surface-50 dark:bg-surface-800/50 border-t border-surface-200 dark:border-surface-700 flex justify-between">
            <div class="flex gap-1">
              <button 
                v-if="!conn.is_default"
                @click="setDefault(conn)"
                class="btn-ghost btn-sm text-primary-500"
                title="Set as default"
              >
                <span class="material-symbols-rounded">star</span>
              </button>
              <button 
                @click="openEditModal(conn)"
                class="btn-ghost btn-sm text-primary-500"
                title="Edit"
              >
                <span class="material-symbols-rounded">edit</span>
              </button>
            </div>
            <button 
              v-if="!conn.is_default"
              @click="deleteModal = { show: true, connection: conn }"
              class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
              title="Delete"
            >
              <span class="material-symbols-rounded">delete</span>
            </button>
          </div>
        </div>

        <!-- Empty state -->
        <div v-if="!connections.length" class="col-span-full card py-12 text-center text-surface-400">
          <span class="material-symbols-rounded text-4xl mb-2 block">hard_drive</span>
          <p class="mb-4">No storage connections yet</p>
          <button @click="setupWizardModal = true" class="btn-primary mx-auto">
            <span class="material-symbols-rounded">auto_fix_high</span>
            Connect NAS
          </button>
        </div>
      </div>

      <!-- Domain Overrides Section -->
      <div v-if="domainOverrides.length > 0" class="mt-8">
        <h2 class="text-lg font-semibold mb-4">Domain Storage Overrides</h2>
        <div class="card overflow-hidden">
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr class="bg-surface-50 dark:bg-surface-800/50">
                  <th>Domain</th>
                  <th>NAS Connection</th>
                  <th>Mount Point</th>
                  <th>Sub Path</th>
                  <th class="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="override in domainOverrides" :key="override.id">
                  <td>
                    <span class="font-medium">{{ override.domain }}</span>
                  </td>
                  <td>{{ override.nas_name }}</td>
                  <td class="text-surface-500">{{ override.mount_point }}</td>
                  <td class="text-surface-500">{{ override.sub_path || '-' }}</td>
                  <td class="text-right">
                    <button 
                      @click="removeDomainOverride(override.domain)"
                      class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                      title="Remove override"
                    >
                      <span class="material-symbols-rounded">link_off</span>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Troubleshooting Panel -->
      <NasTroubleshootingPanel :connections="connections" :vpn-connections="vpnConnections" />
    </div>

    <!-- ==================== VPN TAB ==================== -->
    <div v-if="activeTab === 'vpn'">
      <!-- Info Box -->
      <div class="card mb-6 overflow-hidden">
        <button 
          @click="vpnInfoExpanded = !vpnInfoExpanded"
          class="w-full p-4 flex items-center justify-between hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
        >
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">info</span>
            </div>
            <div class="text-left">
              <p class="font-medium">How VPN & NAS Work Together</p>
              <p class="text-sm text-surface-500">Understanding VPN connections for remote storage</p>
            </div>
          </div>
          <span class="material-symbols-rounded text-surface-400 transition-transform" :class="vpnInfoExpanded ? 'rotate-180' : ''">
            expand_more
          </span>
        </button>
        
        <transition
          enter-active-class="transition-all duration-200 ease-out"
          enter-from-class="max-h-0 opacity-0"
          enter-to-class="max-h-96 opacity-100"
          leave-active-class="transition-all duration-150 ease-in"
          leave-from-class="max-h-96 opacity-100"
          leave-to-class="max-h-0 opacity-0"
        >
          <div v-if="vpnInfoExpanded" class="overflow-hidden">
            <div class="px-4 pb-4 border-t border-surface-200 dark:border-surface-700">
              <div class="grid md:grid-cols-3 gap-4 mt-4">
                <!-- One VPN Multiple NAS -->
                <div class="p-4 bg-green-50 dark:bg-green-500/10 rounded-xl">
                  <div class="flex items-center gap-2 mb-2">
                    <span class="material-symbols-rounded text-green-600 dark:text-green-400">check_circle</span>
                    <h4 class="font-medium text-green-700 dark:text-green-300">One VPN = Multiple NAS</h4>
                  </div>
                  <p class="text-sm text-green-600 dark:text-green-400">
                    A single VPN can connect to multiple NAS devices on the same remote network. 
                    No need for separate VPNs per device.
                  </p>
                </div>
                
                <!-- New VPN for Different Network -->
                <div class="p-4 bg-amber-50 dark:bg-amber-500/10 rounded-xl">
                  <div class="flex items-center gap-2 mb-2">
                    <span class="material-symbols-rounded text-amber-600 dark:text-amber-400">add_circle</span>
                    <h4 class="font-medium text-amber-700 dark:text-amber-300">New VPN = New Network</h4>
                  </div>
                  <p class="text-sm text-amber-600 dark:text-amber-400">
                    Only create a new VPN when connecting to a different remote network 
                    (e.g., home, office, client site).
                  </p>
                </div>
                
                <!-- No VPN Needed -->
                <div class="p-4 bg-blue-50 dark:bg-blue-500/10 rounded-xl">
                  <div class="flex items-center gap-2 mb-2">
                    <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">public_off</span>
                    <h4 class="font-medium text-blue-700 dark:text-blue-300">No VPN Needed</h4>
                  </div>
                  <p class="text-sm text-blue-600 dark:text-blue-400">
                    Local NAS (same datacenter) or NAS with public IP don't require VPN. 
                    Connect directly via NFS/CIFS.
                  </p>
                </div>
              </div>
              
              <!-- Example -->
              <div class="mt-4 p-4 bg-surface-100 dark:bg-surface-800 rounded-xl">
                <h4 class="font-medium text-sm mb-2">Example Setup</h4>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                  <span class="px-3 py-1 bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300 rounded-lg font-mono">synology VPN</span>
                  <span class="material-symbols-rounded text-surface-400">arrow_forward</span>
                  <span class="text-surface-600 dark:text-surface-400">connects to 192.168.1.0/24</span>
                  <span class="material-symbols-rounded text-surface-400">arrow_forward</span>
                  <span class="px-2 py-1 bg-surface-200 dark:bg-surface-700 rounded text-surface-600 dark:text-surface-400">Synology at .106</span>
                  <span class="text-surface-400">+</span>
                  <span class="px-2 py-1 bg-surface-200 dark:bg-surface-700 rounded text-surface-600 dark:text-surface-400">QNAP at .107</span>
                  <span class="text-surface-400">+</span>
                  <span class="px-2 py-1 bg-surface-200 dark:bg-surface-700 rounded text-surface-600 dark:text-surface-400">Any device on same network</span>
                </div>
              </div>
            </div>
          </div>
        </transition>
      </div>

      <!-- VPN Stats -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4 mb-6">
        <div class="stat-card">
          <p class="text-surface-500 text-sm">Total</p>
          <p class="stat-value">{{ vpnStats.total }}</p>
        </div>
        <div class="stat-card">
          <p class="text-surface-500 text-sm">Connected</p>
          <p class="stat-value text-green-600">{{ vpnStats.connected }}</p>
        </div>
        <div class="stat-card">
          <p class="text-surface-500 text-sm">Disconnected</p>
          <p class="stat-value text-amber-600">{{ vpnStats.disconnected }}</p>
        </div>
        <div class="stat-card">
          <p class="text-surface-500 text-sm">Error</p>
          <p class="stat-value text-red-600">{{ vpnStats.error }}</p>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="vpnLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <!-- VPN Connections Table -->
      <div v-else class="card overflow-hidden">
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr class="bg-surface-50 dark:bg-surface-800/50">
                <th>Name</th>
                <th>Status</th>
                <th>Local IP</th>
                <th>Remote IP</th>
                <th>Connected</th>
                <th class="text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="vpn in vpnConnections" :key="vpn.name">
                <td>
                  <div class="flex items-center gap-3">
                    <div 
                      class="w-10 h-10 rounded-xl flex items-center justify-center"
                      :class="vpn.status === 'connected' ? 'bg-green-100 dark:bg-green-500/20' : 'bg-surface-100 dark:bg-surface-700'"
                    >
                      <span 
                        class="material-symbols-rounded"
                        :class="vpn.status === 'connected' ? 'text-green-600 dark:text-green-400' : 'text-surface-500'"
                      >
                        {{ getVpnStatusIcon(vpn.status) }}
                      </span>
                    </div>
                    <div>
                      <p class="font-medium">{{ vpn.name }}</p>
                      <p v-if="vpn.description" class="text-xs text-surface-500">{{ vpn.description }}</p>
                    </div>
                  </div>
                </td>
                <td>
                  <StatusBadge :status="getStatusColor(vpn.status)" :label="vpn.status" />
                </td>
                <td>
                  <span class="font-mono text-sm">{{ vpn.local_ip || '-' }}</span>
                </td>
                <td>
                  <span class="font-mono text-sm">{{ vpn.remote_ip || '-' }}</span>
                </td>
                <td>
                  <span class="text-sm text-surface-500">{{ vpn.connected_at ? formatDate(vpn.connected_at) : '-' }}</span>
                </td>
                <td class="text-right">
                  <div class="flex justify-end gap-1">
                    <!-- Start/Stop button -->
                    <button 
                      v-if="vpn.status !== 'connected'"
                      @click="startVpn(vpn)"
                      class="btn-ghost btn-sm text-green-500"
                      :disabled="vpnAction === vpn.name"
                      title="Start VPN"
                    >
                      <span v-if="vpnAction === vpn.name" class="spinner-sm"></span>
                      <span v-else class="material-symbols-rounded">play_arrow</span>
                    </button>
                    <button 
                      v-else
                      @click="stopVpn(vpn)"
                      class="btn-ghost btn-sm text-amber-500"
                      :disabled="vpnAction === vpn.name"
                      title="Stop VPN"
                    >
                      <span v-if="vpnAction === vpn.name" class="spinner-sm"></span>
                      <span v-else class="material-symbols-rounded">stop</span>
                    </button>
                    
                    <!-- Restart button -->
                    <button 
                      @click="restartVpn(vpn)"
                      class="btn-ghost btn-sm text-blue-500"
                      :disabled="vpnAction === vpn.name"
                      title="Restart VPN"
                    >
                      <span class="material-symbols-rounded">refresh</span>
                    </button>
                    
                    <!-- Logs button -->
                    <button 
                      @click="openVpnLogs(vpn)"
                      class="btn-ghost btn-sm text-primary-500"
                      title="View Logs"
                    >
                      <span class="material-symbols-rounded">description</span>
                    </button>

                    <!-- Re-import config button -->
                    <button 
                      @click="triggerVpnReimport(vpn)"
                      class="btn-ghost btn-sm text-primary-500"
                      :disabled="vpnAction === vpn.name"
                      title="Re-import .ovpn config (updates and restarts tunnel)"
                    >
                      <span class="material-symbols-rounded">upload_file</span>
                    </button>
                    
                    <!-- Delete button -->
                    <button 
                      @click="vpnDeleteModal = { show: true, vpn }"
                      class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                      title="Delete VPN"
                    >
                      <span class="material-symbols-rounded">delete</span>
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!vpnConnections.length">
                <td colspan="6" class="py-12 text-center text-surface-400">
                  <span class="material-symbols-rounded text-4xl mb-2 block">vpn_key_off</span>
                  No VPN connections found
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>


    <!-- ==================== MODALS ==================== -->

    <!-- Edit NAS Modal -->
    <Modal :show="editModal.show" title="Edit NAS Connection" @close="editModal = { show: false, connection: null }" size="lg">
      <form v-if="editModal.connection" @submit.prevent="updateConnection" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div class="col-span-2 sm:col-span-1">
            <label class="block text-sm font-medium mb-2">Name</label>
            <input
              v-model="editModal.connection.name"
              type="text"
              class="input"
              required
            />
          </div>

          <div class="col-span-2 sm:col-span-1">
            <label class="block text-sm font-medium mb-2">Driver Type</label>
            <select v-model="editModal.connection.driver" class="input">
              <option value="local">Local</option>
              <option value="nfs">NFS</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Mount Point</label>
          <input
            v-model="editModal.connection.mount_point"
            type="text"
            class="input"
            required
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Status</label>
          <select v-model="editModal.connection.status" class="input">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="error">Error</option>
          </select>
        </div>

        <!-- NFS Settings -->
        <div v-if="editModal.connection.driver === 'nfs'" class="space-y-4 p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <h4 class="font-medium text-sm">NFS Settings</h4>
          
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium mb-2">NFS Server</label>
              <input
                v-model="editModal.connection.nfs_server"
                type="text"
                class="input"
              />
            </div>
            <div>
              <label class="block text-sm font-medium mb-2">NFS Path</label>
              <input
                v-model="editModal.connection.nfs_path"
                type="text"
                class="input"
              />
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium mb-2">Mount Options</label>
            <input
              v-model="editModal.connection.nfs_options"
              type="text"
              class="input"
            />
          </div>
        </div>

        <!-- VPN Settings -->
        <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <label class="flex items-center gap-3 cursor-pointer">
            <div class="relative">
              <input 
                type="checkbox" 
                v-model="editModal.connection.vpn_enabled"
                :true-value="1"
                :false-value="0"
                class="sr-only peer"
              />
              <div class="w-11 h-6 bg-surface-300 dark:bg-surface-600 rounded-full peer peer-checked:bg-primary-500 transition-colors"></div>
              <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow peer-checked:translate-x-5 transition-transform"></div>
            </div>
            <div>
              <span class="font-medium">VPN Required</span>
            </div>
          </label>

          <div v-if="editModal.connection.vpn_enabled" class="mt-4">
            <label class="block text-sm font-medium mb-2">VPN Connection</label>
            <select v-model="editModal.connection.vpn_name" class="input">
              <option value="">Select VPN...</option>
              <option v-for="vpn in vpnConnections" :key="vpn.name" :value="vpn.name">
                {{ vpn.name }} ({{ vpn.status }})
              </option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Notes</label>
          <textarea
            v-model="editModal.connection.notes"
            class="input"
            rows="2"
          ></textarea>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="editModal = { show: false, connection: null }" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Save Changes
          </button>
        </div>
      </form>
    </Modal>

    <!-- Stats Modal -->
    <Modal :show="statsModal.show" title="Storage Statistics" @close="statsModal = { show: false, connection: null, stats: null, loading: false }">
      <div v-if="statsModal.loading" class="flex items-center justify-center py-8">
        <span class="spinner"></span>
      </div>
      <div v-else-if="statsModal.stats" class="space-y-4">
        <div class="text-center mb-6">
          <h3 class="font-semibold text-lg">{{ statsModal.connection?.name }}</h3>
          <p class="text-surface-500 text-sm">{{ statsModal.connection?.mount_point }}</p>
        </div>

        <!-- Usage Bar -->
        <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <div class="flex justify-between text-sm mb-2">
            <span>Used: {{ statsModal.stats.human?.used }}</span>
            <span>{{ statsModal.stats.used_percent }}%</span>
          </div>
          <div class="h-4 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
            <div 
              class="h-full rounded-full transition-all"
              :class="statsModal.stats.used_percent > 90 ? 'bg-red-500' : statsModal.stats.used_percent > 70 ? 'bg-amber-500' : 'bg-green-500'"
              :style="{ width: statsModal.stats.used_percent + '%' }"
            ></div>
          </div>
          <div class="flex justify-between text-xs text-surface-500 mt-2">
            <span>Free: {{ statsModal.stats.human?.free }}</span>
            <span>Total: {{ statsModal.stats.human?.total }}</span>
          </div>
        </div>

        <!-- Inode Stats - Only show if valid data available -->
        <div v-if="statsModal.stats.inodes && statsModal.stats.inodes.total > 0" class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <h4 class="font-medium text-sm mb-3">Inodes</h4>
          <div class="grid grid-cols-3 gap-4 text-center">
            <div>
              <p class="text-2xl font-bold">{{ statsModal.stats.inodes.used?.toLocaleString() || 0 }}</p>
              <p class="text-xs text-surface-500">Used</p>
            </div>
            <div>
              <p class="text-2xl font-bold">{{ statsModal.stats.inodes.free?.toLocaleString() || 0 }}</p>
              <p class="text-xs text-surface-500">Free</p>
            </div>
            <div>
              <p class="text-2xl font-bold">{{ statsModal.stats.inodes.used_percent || 0 }}%</p>
              <p class="text-xs text-surface-500">Usage</p>
            </div>
          </div>
        </div>
        <!-- Note when inodes not available (common for NFS) -->
        <div v-else-if="statsModal.stats.mount?.fstype?.includes('nfs')" class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <div class="flex items-center gap-2 text-surface-400 text-sm">
            <span class="material-symbols-rounded text-base">info</span>
            <span>Inode statistics not available for NFS mounts</span>
          </div>
        </div>

        <!-- Mount Info -->
        <div v-if="statsModal.stats.mount" class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
          <h4 class="font-medium text-sm mb-3">Mount Information</h4>
          <div class="space-y-2 text-sm">
            <div class="flex justify-between">
              <span class="text-surface-500">Source:</span>
              <span class="font-mono">{{ statsModal.stats.mount.source }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-surface-500">Filesystem:</span>
              <span>{{ statsModal.stats.mount.fstype }}</span>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="text-center py-8 text-surface-400">
        Failed to load statistics
      </div>
    </Modal>

    <!-- Assign Domain Modal -->
    <Modal :show="assignModal.show" title="Assign Domain" @close="assignModal = { show: false, connection: null }">
      <div v-if="assignModal.connection" class="space-y-4">
        <p class="text-sm text-surface-500">
          Assign a domain to use <strong>{{ assignModal.connection.name }}</strong> instead of the default storage.
        </p>

        <div>
          <label class="block text-sm font-medium mb-2">Domain</label>
          <select v-model="assignModal.selectedDomain" class="input">
            <option value="">Select a domain...</option>
            <option v-for="domain in availableDomains" :key="domain.domain" :value="domain.domain">
              {{ domain.domain }}
            </option>
          </select>
          <p v-if="availableDomains.length === 0" class="text-xs text-amber-600 mt-1">
            All domains already have storage assignments
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Sub Path (optional)</label>
          <input
            v-model="assignModal.subPath"
            type="text"
            class="input"
            placeholder="e.g., domain-specific-folder"
          />
          <p class="text-xs text-surface-500 mt-1">Optional subdirectory within the mount point</p>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button @click="assignModal = { show: false, connection: null }" class="btn-secondary">
            Cancel
          </button>
          <button 
            @click="assignDomain" 
            class="btn-primary" 
            :disabled="submitting || !assignModal.selectedDomain"
          >
            <span v-if="submitting" class="spinner"></span>
            Assign Domain
          </button>
        </div>
      </div>
    </Modal>

    <!-- Delete NAS Confirm Modal -->
    <ConfirmModal
      :show="deleteModal.show"
      title="Delete NAS Connection"
      :message="`Are you sure you want to delete '${deleteModal.connection?.name}'? This will remove all domain assignments for this connection.`"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      @confirm="deleteConnection"
      @cancel="deleteModal = { show: false, connection: null }"
    />

    <!-- Create VPN Modal - Multi-Step Wizard -->
    <Modal :show="vpnCreateModal" title="New VPN Connection" @close="vpnCreateModal = false; resetNewVpn()" size="lg">
      <!-- Mode Toggle -->
      <div class="flex gap-1 p-1 bg-surface-100 dark:bg-surface-800 rounded-xl w-fit mb-6">
        <button
          type="button"
          @click="vpnCreateMode = 'guided'; vpnWizardStep = 1"
          :class="[
            'px-4 py-2 rounded-lg text-sm font-medium transition-all',
            vpnCreateMode === 'guided' 
              ? 'bg-white dark:bg-surface-700 shadow text-primary-600 dark:text-primary-400' 
              : 'text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-200'
          ]"
        >
          <span class="material-symbols-rounded text-lg align-middle mr-1">tune</span>
          Guided Setup
        </button>
        <button
          type="button"
          @click="vpnCreateMode = 'advanced'"
          :class="[
            'px-4 py-2 rounded-lg text-sm font-medium transition-all',
            vpnCreateMode === 'advanced' 
              ? 'bg-white dark:bg-surface-700 shadow text-primary-600 dark:text-primary-400' 
              : 'text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-200'
          ]"
        >
          <span class="material-symbols-rounded text-lg align-middle mr-1">code</span>
          Paste Config
        </button>
      </div>

      <!-- ==================== GUIDED WIZARD ==================== -->
      <div v-if="vpnCreateMode === 'guided'">
        <!-- Step Indicators -->
        <div class="flex items-center justify-between mb-8">
          <template v-for="(step, idx) in vpnWizardSteps" :key="step.num">
            <div class="flex items-center">
              <button
                type="button"
                @click="step.num < vpnWizardStep ? vpnWizardStep = step.num : null"
                :class="[
                  'w-10 h-10 rounded-full flex items-center justify-center transition-all',
                  vpnWizardStep === step.num 
                    ? 'bg-primary-500 text-white shadow-lg scale-110' 
                    : vpnWizardStep > step.num 
                      ? 'bg-green-500 text-white cursor-pointer hover:scale-105' 
                      : 'bg-surface-200 dark:bg-surface-700 text-surface-500'
                ]"
              >
                <span v-if="vpnWizardStep > step.num" class="material-symbols-rounded text-lg">check</span>
                <span v-else class="material-symbols-rounded text-lg">{{ step.icon }}</span>
              </button>
            </div>
            <div 
              v-if="idx < vpnWizardSteps.length - 1" 
              :class="[
                'flex-1 h-1 mx-2 rounded transition-colors',
                vpnWizardStep > step.num ? 'bg-green-500' : 'bg-surface-200 dark:bg-surface-700'
              ]"
            ></div>
          </template>
        </div>

        <!-- Step Title -->
        <div class="text-center mb-6">
          <h3 class="text-xl font-semibold">{{ vpnWizardSteps[vpnWizardStep - 1].title }}</h3>
          <p class="text-sm text-surface-500 mt-1">Step {{ vpnWizardStep }} of {{ vpnWizardSteps.length }}</p>
        </div>

        <!-- Import .ovpn shortcut on Step 1 -->
        <div v-if="vpnWizardStep === 1" class="mb-6 p-4 bg-primary-50 dark:bg-primary-500/10 rounded-xl border border-primary-200 dark:border-primary-500/30">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-primary-600 dark:text-primary-400 text-2xl">upload_file</span>
              <div>
                <p class="font-medium text-primary-700 dark:text-primary-300">Have a .ovpn file?</p>
                <p class="text-sm text-primary-600 dark:text-primary-400">Import it to auto-fill all settings</p>
              </div>
            </div>
            <label class="btn-primary cursor-pointer">
              <span class="material-symbols-rounded text-sm">upload</span>
              Import .ovpn
              <input type="file" class="hidden" @change="handleOvpnImport" accept=".ovpn,.conf" />
            </label>
          </div>
        </div>

        <!-- Step 1: Basic Info -->
        <div v-if="vpnWizardStep === 1" class="space-y-4">
          <div>
            <label class="block text-sm font-medium mb-2">Connection Name <span class="text-red-500">*</span></label>
            <input
              v-model="newVpn.name"
              type="text"
              class="input text-lg"
              placeholder="synology"
              pattern="[a-zA-Z0-9_-]+"
              autofocus
            />
            <p class="text-xs text-surface-500 mt-2">A unique name for this VPN connection. Use only letters, numbers, dash, or underscore.</p>
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Description</label>
            <input
              v-model="newVpn.description"
              type="text"
              class="input"
              placeholder="VPN connection to home Synology NAS"
            />
            <p class="text-xs text-surface-500 mt-2">Optional description to help identify this connection.</p>
          </div>
        </div>

        <!-- Step 2: Connection -->
        <div v-if="vpnWizardStep === 2" class="space-y-4">
          <div>
            <label class="block text-sm font-medium mb-2">Server Address <span class="text-red-500">*</span></label>
            <input
              v-model="newVpn.server"
              type="text"
              class="input text-lg"
              placeholder="vpn.example.com or 203.0.113.50"
              autofocus
            />
            <p class="text-xs text-surface-500 mt-2">The hostname or IP address of your OpenVPN server.</p>
          </div>
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium mb-2">Port</label>
              <input
                v-model.number="newVpn.port"
                type="number"
                class="input"
                placeholder="1194"
              />
              <p class="text-xs text-surface-500 mt-2">Default: 1194</p>
            </div>
            <div>
              <label class="block text-sm font-medium mb-2">Protocol</label>
              <select v-model="newVpn.protocol" class="input">
                <option value="udp">UDP (faster)</option>
                <option value="tcp">TCP (more reliable)</option>
              </select>
              <p class="text-xs text-surface-500 mt-2">UDP is recommended for most cases.</p>
            </div>
          </div>
        </div>

        <!-- Step 3: Security -->
        <div v-if="vpnWizardStep === 3" class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium mb-2">Device Type</label>
              <select v-model="newVpn.device" class="input">
                <option value="tun">TUN (Layer 3 - IP)</option>
                <option value="tap">TAP (Layer 2 - Ethernet)</option>
              </select>
              <p class="text-xs text-surface-500 mt-2">TUN is most common for routing.</p>
            </div>
            <div>
              <label class="block text-sm font-medium mb-2">Cipher</label>
              <select v-model="newVpn.cipher" class="input">
                <option value="AES-256-GCM">AES-256-GCM (recommended)</option>
                <option value="AES-128-GCM">AES-128-GCM</option>
                <option value="AES-256-CBC">AES-256-CBC (legacy)</option>
                <option value="AES-128-CBC">AES-128-CBC (legacy)</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium mb-2">Auth Digest</label>
              <select v-model="newVpn.auth" class="input">
                <option value="SHA256">SHA256 (recommended)</option>
                <option value="SHA512">SHA512</option>
                <option value="SHA1">SHA1 (legacy)</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium mb-2">Compression</label>
              <select v-model="newVpn.compression" class="input">
                <option value="none">None (recommended)</option>
                <option value="lzo">LZO (legacy)</option>
                <option value="lz4">LZ4</option>
                <option value="lz4-v2">LZ4-v2</option>
              </select>
            </div>
          </div>
          <p class="text-xs text-surface-500 p-3 bg-surface-100 dark:bg-surface-800 rounded-lg">
            <span class="material-symbols-rounded text-sm align-middle mr-1">info</span>
            These settings must match your VPN server configuration. If unsure, check your server's .ovpn file.
          </p>
        </div>

        <!-- Step 4: Certificates -->
        <div v-if="vpnWizardStep === 4" class="space-y-4">
          <!-- CA Certificate -->
          <div>
            <div class="flex items-center justify-between mb-2">
              <label class="block text-sm font-medium">CA Certificate <span class="text-red-500">*</span></label>
              <label class="btn-sm btn-primary cursor-pointer">
                <span class="material-symbols-rounded text-sm">upload_file</span>
                Upload File
                <input type="file" class="hidden" @change="handleCertUpload($event, 'ca_cert')" accept=".crt,.pem,.txt,.ovpn,.ca" />
              </label>
            </div>
            <textarea
              v-model="newVpn.ca_cert"
              class="input font-mono text-xs"
              rows="5"
              placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"
            ></textarea>
          </div>

          <!-- Client Certificate -->
          <div>
            <div class="flex items-center justify-between mb-2">
              <label class="block text-sm font-medium">Client Certificate <span class="text-surface-400">(optional)</span></label>
              <label class="btn-sm btn-secondary cursor-pointer">
                <span class="material-symbols-rounded text-sm">upload_file</span>
                Upload
                <input type="file" class="hidden" @change="handleCertUpload($event, 'client_cert')" accept=".crt,.pem,.txt" />
              </label>
            </div>
            <textarea
              v-model="newVpn.client_cert"
              class="input font-mono text-xs"
              rows="4"
              placeholder="-----BEGIN CERTIFICATE-----&#10;..."
            ></textarea>
          </div>

          <!-- Client Key -->
          <div>
            <div class="flex items-center justify-between mb-2">
              <label class="block text-sm font-medium">Client Private Key <span class="text-surface-400">(optional)</span></label>
              <label class="btn-sm btn-secondary cursor-pointer">
                <span class="material-symbols-rounded text-sm">upload_file</span>
                Upload
                <input type="file" class="hidden" @change="handleCertUpload($event, 'client_key')" accept=".key,.pem,.txt" />
              </label>
            </div>
            <textarea
              v-model="newVpn.client_key"
              class="input font-mono text-xs"
              rows="4"
              placeholder="-----BEGIN PRIVATE KEY-----&#10;..."
            ></textarea>
          </div>

          <!-- TLS Auth -->
          <div class="p-3 bg-surface-100 dark:bg-surface-800 rounded-lg">
            <div class="flex items-center justify-between mb-2">
              <label class="block text-sm font-medium">TLS Auth Key <span class="text-surface-400">(optional)</span></label>
              <div class="flex items-center gap-2">
                <select v-model="newVpn.tls_direction" class="input w-auto text-xs py-1">
                  <option value="">Auto</option>
                  <option value="0">Direction 0</option>
                  <option value="1">Direction 1</option>
                </select>
                <label class="btn-sm btn-secondary cursor-pointer">
                  <span class="material-symbols-rounded text-sm">upload_file</span>
                  <input type="file" class="hidden" @change="handleCertUpload($event, 'tls_auth')" accept=".key,.pem,.txt" />
                </label>
              </div>
            </div>
            <textarea
              v-model="newVpn.tls_auth"
              class="input font-mono text-xs"
              rows="3"
              placeholder="-----BEGIN OpenVPN Static key V1-----&#10;..."
            ></textarea>
          </div>
        </div>

        <!-- Step 5: Routes -->
        <div v-if="vpnWizardStep === 5" class="space-y-4">
          <div class="p-4 bg-blue-50 dark:bg-blue-500/10 rounded-xl mb-4">
            <div class="flex items-start gap-3">
              <span class="material-symbols-rounded text-blue-600 dark:text-blue-400">info</span>
              <div class="text-sm text-blue-700 dark:text-blue-300">
                <p class="font-medium">Custom Routes</p>
                <p class="mt-1">Add networks that should be routed through this VPN. For example, if your NAS is on 192.168.1.0/24, add that network here.</p>
              </div>
            </div>
          </div>

          <div class="flex items-center justify-between mb-2">
            <label class="block text-sm font-medium">Networks to Route</label>
            <button type="button" @click="addRoute" class="btn-sm btn-primary">
              <span class="material-symbols-rounded text-sm">add</span>
              Add Route
            </button>
          </div>
          
          <div v-if="newVpn.routes.length === 0" class="text-center py-8 text-surface-400 border-2 border-dashed border-surface-200 dark:border-surface-700 rounded-xl">
            <span class="material-symbols-rounded text-3xl mb-2 block">route</span>
            <p>No routes configured</p>
            <p class="text-xs mt-1">Routes are optional but often needed for NAS access</p>
          </div>
          
          <div v-for="(route, index) in newVpn.routes" :key="index" class="flex gap-2 items-center p-3 bg-surface-50 dark:bg-surface-800 rounded-lg">
            <div class="flex-1">
              <label class="text-xs text-surface-500 mb-1 block">Network (CIDR)</label>
              <input
                v-model="route.network"
                type="text"
                class="input"
                placeholder="192.168.1.0/24"
              />
            </div>
            <div class="flex-1">
              <label class="text-xs text-surface-500 mb-1 block">Gateway (optional)</label>
              <input
                v-model="route.gateway"
                type="text"
                class="input"
                placeholder="Auto (VPN gateway)"
              />
            </div>
            <button type="button" @click="removeRoute(index)" class="btn-ghost btn-sm text-red-500 mt-5">
              <span class="material-symbols-rounded">delete</span>
            </button>
          </div>

          <!-- Optional: Username/Password -->
          <div class="pt-4 border-t border-surface-200 dark:border-surface-700">
            <label class="block text-sm font-medium mb-2">Authentication (if required)</label>
            <div class="grid grid-cols-2 gap-4">
              <input
                v-model="newVpn.username"
                type="text"
                class="input"
                placeholder="Username (optional)"
              />
              <input
                v-model="newVpn.password"
                type="password"
                class="input"
                placeholder="Password (optional)"
              />
            </div>
            <p class="text-xs text-surface-500 mt-2">Only needed if your VPN requires username/password authentication.</p>
          </div>
        </div>

        <!-- Wizard Navigation -->
        <div class="flex justify-between items-center pt-6 mt-6 border-t border-surface-200 dark:border-surface-700">
          <button 
            type="button" 
            @click="vpnWizardStep > 1 ? prevStep() : (vpnCreateModal = false, resetNewVpn())"
            class="btn-secondary"
          >
            <span class="material-symbols-rounded text-lg">{{ vpnWizardStep > 1 ? 'arrow_back' : 'close' }}</span>
            {{ vpnWizardStep > 1 ? 'Back' : 'Cancel' }}
          </button>
          
          <div class="flex gap-2">
            <button 
              v-if="vpnWizardStep < 5"
              type="button" 
              @click="nextStep"
              class="btn-primary"
              :disabled="!canGoNext"
            >
              Next
              <span class="material-symbols-rounded text-lg">arrow_forward</span>
            </button>
            <button 
              v-else
              type="button"
              @click="submitVpn"
              class="btn-primary"
              :disabled="submitting || !canGoNext"
            >
              <span v-if="submitting" class="spinner"></span>
              <span v-else class="material-symbols-rounded text-lg">check</span>
              Create VPN
            </button>
          </div>
        </div>
      </div>

      <!-- ==================== ADVANCED MODE ==================== -->
      <form v-if="vpnCreateMode === 'advanced'" @submit.prevent="submitVpn" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Name <span class="text-red-500">*</span></label>
            <input
              v-model="newVpn.name"
              type="text"
              class="input"
              placeholder="synology"
              required
              pattern="[a-zA-Z0-9_-]+"
            />
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Description</label>
            <input
              v-model="newVpn.description"
              type="text"
              class="input"
              placeholder="VPN to Synology NAS"
            />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">OpenVPN Config <span class="text-red-500">*</span></label>
          <textarea
            v-model="newVpn.config_content"
            class="input font-mono text-sm"
            rows="12"
            placeholder="Paste your .ovpn config content here..."
            required
          ></textarea>
          <p class="text-xs text-surface-500 mt-1">Paste the full contents of your .ovpn file</p>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Up Script (optional)</label>
            <textarea
              v-model="newVpn.up_script"
              class="input font-mono text-sm"
              rows="4"
              placeholder="#!/bin/bash&#10;ip route add 192.168.1.0/24 via $route_vpn_gateway dev $1"
            ></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium mb-2">Down Script (optional)</label>
            <textarea
              v-model="newVpn.down_script"
              class="input font-mono text-sm"
              rows="4"
              placeholder="#!/bin/bash&#10;ip route del 192.168.1.0/24"
            ></textarea>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4 border-t border-surface-200 dark:border-surface-700">
          <button type="button" @click="vpnCreateModal = false; resetNewVpn()" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Create VPN
          </button>
        </div>
      </form>
    </Modal>

    <!-- VPN Logs Modal -->
    <Modal :show="vpnLogsModal.show" title="VPN Logs" @close="vpnLogsModal = { show: false, vpn: null, logs: '', loading: false }" size="lg">
      <div v-if="vpnLogsModal.loading" class="flex items-center justify-center py-8">
        <span class="spinner"></span>
      </div>
      <div v-else>
        <div class="flex items-center justify-between mb-4">
          <h3 class="font-semibold">{{ vpnLogsModal.vpn?.name }}</h3>
          <button @click="openVpnLogs(vpnLogsModal.vpn)" class="btn-sm btn-secondary">
            <span class="material-symbols-rounded">refresh</span>
            Refresh
          </button>
        </div>
        <pre class="bg-surface-900 text-surface-100 p-4 rounded-xl text-xs font-mono overflow-auto max-h-[400px] whitespace-pre-wrap">{{ vpnLogsModal.logs }}</pre>
      </div>
    </Modal>

    <!-- Delete VPN Confirm Modal -->
    <ConfirmModal
      :show="vpnDeleteModal.show"
      title="Delete VPN Connection"
      :message="`Are you sure you want to delete VPN '${vpnDeleteModal.vpn?.name}'? This will stop the VPN and remove its configuration.`"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      @confirm="deleteVpn"
      @cancel="vpnDeleteModal = { show: false, vpn: null }"
    />

    <!-- One-Click NAS Setup Wizard -->
    <NasSetupWizard
      :show="setupWizardModal"
      :vpn-connections="vpnConnections"
      @close="setupWizardModal = false"
      @completed="fetchConnections(); fetchVpnConnections()"
    />

    <!-- Hidden file input shared by the per-row VPN re-import buttons -->
    <input
      ref="vpnReimportInput"
      type="file"
      accept=".ovpn,.conf,.txt"
      class="hidden"
      @change="handleVpnReimport"
    />
  </div>
</template>
