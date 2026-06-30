<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import aiHelper from '@/services/aiHelper'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import ConfigEditor from '@/components/ConfigEditor.vue'
import ConfigGuideModal from '@/components/ConfigGuideModal.vue'
import { marked } from 'marked'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

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

// Active tab
const activeTab = ref('overview')
const showGuide = ref(false)
const guideService = ref('')

// Tab scroll indicators
const tabsNav = ref(null)
const canScrollLeft = ref(false)
const canScrollRight = ref(true)

const updateScrollIndicators = () => {
  if (!tabsNav.value) return
  const el = tabsNav.value
  canScrollLeft.value = el.scrollLeft > 5
  canScrollRight.value = el.scrollWidth > el.clientWidth && el.scrollLeft < (el.scrollWidth - el.clientWidth - 5)
}

const scrollToStart = () => {
  if (!tabsNav.value) return
  tabsNav.value.scrollLeft = 0
  setTimeout(updateScrollIndicators, 50)
}

const scrollTabs = (direction) => {
  if (!tabsNav.value) return
  const scrollAmount = 150
  tabsNav.value.scrollBy({
    left: direction === 'left' ? -scrollAmount : scrollAmount,
    behavior: 'smooth'
  })
  setTimeout(updateScrollIndicators, 300)
}

const openGuide = (service) => {
  guideService.value = service
  showGuide.value = true
}

// Handle opening a config file from the guide
const handleOpenFile = (filePath) => {
  if (!filePath) return
  
  // Check if it's a Postfix file
  if (filePath.startsWith('/etc/postfix/')) {
    // Check if this file is in our list
    const postfixFile = postfixConfigFiles.find(f => f.path === filePath)
    if (postfixFile) {
      postfixSelectedConfigFile.value = filePath
      postfixRawMode.value = true
      activeTab.value = 'postfix'
    } else {
      // File not in dropdown - just switch to postfix tab
      activeTab.value = 'postfix'
    }
    return
  }
  
  // Check if it's a Dovecot file
  if (filePath.startsWith('/etc/dovecot/')) {
    // Check if this file is in our list
    const dovecotFile = dovecotConfigFiles.find(f => f.path === filePath)
    if (dovecotFile) {
      dovecotSelectedConfigFile.value = filePath
      dovecotRawMode.value = true
      activeTab.value = 'dovecot'
    } else {
      // File not in dropdown - just switch to dovecot tab
      activeTab.value = 'dovecot'
    }
    return
  }
}

// Parse raw config content into key-value pairs (for reactive guide updates)
const parseRawConfig = (content, format = 'default') => {
  if (!content) return {}
  const settings = {}
  const lines = content.split('\n')
  
  for (const line of lines) {
    const trimmed = line.trim()
    // Skip comments and empty lines
    if (!trimmed || trimmed.startsWith('#') || trimmed.startsWith(';')) continue
    
    // Handle different formats
    let match
    if (format === 'dovecot') {
      // Dovecot: key = value (with optional < prefix for file paths)
      match = trimmed.match(/^([a-z_][a-z0-9_]*)\s*=\s*(.*)$/i)
    } else if (format === 'postfix') {
      // Postfix: key = value
      match = trimmed.match(/^([a-z_][a-z0-9_]*)\s*=\s*(.*)$/i)
    } else if (format === 'ini') {
      // INI style: key = value
      match = trimmed.match(/^([a-z_][a-z0-9_.]*)\s*=\s*(.*)$/i)
    } else {
      // Default: key = value or key value
      match = trimmed.match(/^([a-zA-Z_][a-zA-Z0-9_]*)\s*[=\s]\s*(.*)$/)
    }
    
    if (match) {
      const key = match[1].trim()
      let value = match[2].trim()
      // Remove trailing comments
      const commentIdx = value.indexOf('#')
      if (commentIdx > 0 && value[commentIdx-1] === ' ') {
        value = value.substring(0, commentIdx).trim()
      }
      // Remove quotes if present
      if ((value.startsWith('"') && value.endsWith('"')) || 
          (value.startsWith("'") && value.endsWith("'"))) {
        value = value.slice(1, -1)
      }
      settings[key] = value
    }
  }
  return settings
}

// Check if guide is showing live parsed values from raw editor
const isGuideLive = computed(() => {
  if (guideService.value === 'postfix' && postfixRawMode.value && postfixRawConfig.value) return true
  if (guideService.value === 'dovecot' && dovecotRawMode.value && dovecotRawConfig.value) return true
  return false
})

// Get current settings for the active guide service - REACTIVE to raw config edits
const guideCurrentSettings = computed(() => {
  switch (guideService.value) {
    case 'ssh': 
      return sshSettings.value
    case 'postfix': 
      // If in raw mode, parse the raw config for live updates
      if (postfixRawMode.value && postfixRawConfig.value) {
        return { ...postfixSettings.value, ...parseRawConfig(postfixRawConfig.value, 'postfix') }
      }
      return postfixSettings.value
    case 'dovecot': 
      // If in raw mode, parse the raw config for live updates
      if (dovecotRawMode.value && dovecotRawConfig.value) {
        return { ...dovecotSettings.value, ...parseRawConfig(dovecotRawConfig.value, 'dovecot') }
      }
      return dovecotSettings.value
    case 'mysql': 
      return mysqlSettings.value
    case 'php': 
      return phpSettings.value
    case 'pdns': 
      return pdnsSettings.value
    default: 
      return {}
  }
})

// Grouped tabs configuration
const tabGroups = [
  {
    name: 'System',
    tabs: [
      { id: 'overview', label: 'Overview', icon: 'monitoring' },
      { id: 'hostname', label: 'Hostname', icon: 'dns' },
      { id: 'timezone', label: 'Timezone', icon: 'schedule' },
      { id: 'ssh', label: 'SSH', icon: 'terminal' },
      { id: 'swap', label: 'Swap', icon: 'memory' },
      { id: 'motd', label: 'MOTD', icon: 'campaign' },
      { id: 'templates', label: 'Templates', icon: 'web' },
      { id: 'logs', label: 'Logs', icon: 'article' },
    ]
  },
  {
    name: 'Services',
    tabs: [
      { id: 'ols', label: 'OpenLiteSpeed', icon: 'bolt' },
      { id: 'php', label: 'PHP', icon: 'code' },
      { id: 'mysql', label: 'MySQL', icon: 'database' },
      { id: 'postfix', label: 'Postfix', icon: 'forward_to_inbox' },
      { id: 'dovecot', label: 'Dovecot', icon: 'inbox' },
      { id: 'pdns', label: 'PowerDNS', icon: 'public' },
    ]
  },
  {
    name: 'Email App',
    tabs: [
      { id: 'emailapp', label: 'Email App', icon: 'mail' },
    ]
  }
]

// Flatten tabs for lookup
const tabs = tabGroups.flatMap(g => g.tabs)

// Set active tab from route query
onMounted(() => {
  if (route.query.tab && tabs.find(t => t.id === route.query.tab)) {
    activeTab.value = route.query.tab
  }
  // Initialize scroll indicators - scroll to start
  nextTick(() => {
    scrollToStart()
  })
  // Update on resize
  window.addEventListener('resize', updateScrollIndicators)
})

// Update URL when tab changes
watch(activeTab, (newTab) => {
  router.replace({ query: { tab: newTab } })
})

// ============================================
// System Overview State & Logic
// ============================================
const systemLoading = ref(true)
const systemInfo = ref(null)
const siteCount = ref(10) // Default estimate, will be updated

// Fetch site count for scaling recommendations
const fetchSiteCount = async () => {
  try {
    const response = await api.get('/sites')
    if (response.data.success) {
      siteCount.value = response.data.data?.vhosts?.length || response.data.data?.count || 10
    }
  } catch (e) {
    console.warn('Could not fetch site count for scaling recommendations')
  }
}

const fetchSystemInfo = async () => {
  systemLoading.value = true
  try {
    const response = await api.get('/system/info')
    if (response.data.success) {
      systemInfo.value = response.data.data
    }
  } catch (e) {
    toast.error('Failed to load system information')
  } finally {
    systemLoading.value = false
  }
}

// ============================================
// Hostname State & Logic
// ============================================
const hostnameLoading = ref(true)
const hostnameSaving = ref(false)
const currentHostname = ref('')
const currentFqdn = ref('')
const newHostname = ref('')
const hostnameEditMode = ref(false)

const fetchHostname = async () => {
  hostnameLoading.value = true
  try {
    const response = await api.get('/system/hostname')
    if (response.data.success) {
      currentHostname.value = response.data.data.hostname
      currentFqdn.value = response.data.data.fqdn
      newHostname.value = currentHostname.value
    }
  } catch (e) {
    toast.error('Failed to load hostname')
  } finally {
    hostnameLoading.value = false
  }
}

const saveHostname = async () => {
  hostnameSaving.value = true
  try {
    const response = await api.post('/system/hostname', { hostname: newHostname.value })
    if (response.data.success) {
      toast.success('Hostname updated successfully')
      currentHostname.value = response.data.data.hostname
      currentFqdn.value = response.data.data.fqdn || currentHostname.value
      hostnameEditMode.value = false
    } else {
      toast.error(response.data.error || 'Failed to update hostname')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update hostname')
  } finally {
    hostnameSaving.value = false
  }
}

// ============================================
// Timezone State & Logic
// ============================================
const timezoneLoading = ref(true)
const timezoneSaving = ref(false)
const currentTimezone = ref('')
const currentTime = ref('')
const currentOffset = ref('')
const newTimezone = ref('')
const timezonesList = ref([])
const timezoneSearch = ref('')

const filteredTimezones = computed(() => {
  if (!timezoneSearch.value) return timezonesList.value
  const search = timezoneSearch.value.toLowerCase()
  return timezonesList.value.filter(tz => tz.toLowerCase().includes(search))
})

const fetchTimezone = async () => {
  timezoneLoading.value = true
  try {
    const [tzResponse, listResponse] = await Promise.all([
      api.get('/system/timezone'),
      api.get('/system/timezones')
    ])
    if (tzResponse.data.success) {
      currentTimezone.value = tzResponse.data.data.timezone
      currentTime.value = tzResponse.data.data.time
      currentOffset.value = tzResponse.data.data.utc_offset
      newTimezone.value = currentTimezone.value
    }
    if (listResponse.data.success) {
      timezonesList.value = listResponse.data.data.timezones || []
    }
  } catch (e) {
    toast.error('Failed to load timezone information')
  } finally {
    timezoneLoading.value = false
  }
}

const saveTimezone = async () => {
  timezoneSaving.value = true
  try {
    const response = await api.post('/system/timezone', { timezone: newTimezone.value })
    if (response.data.success) {
      toast.success('Timezone updated successfully')
      currentTimezone.value = response.data.data.timezone
      currentTime.value = response.data.data.time
      currentOffset.value = response.data.data.utc_offset
    } else {
      toast.error(response.data.error || 'Failed to update timezone')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update timezone')
  } finally {
    timezoneSaving.value = false
  }
}

// ============================================
// SSH State & Logic
// ============================================
const sshLoading = ref(true)
const sshSaving = ref(false)
const sshSettings = ref({})
const sshOriginalSettings = ref({})
const sshEditMode = ref(false)
const sshRawMode = ref(false)
const sshRawConfig = ref('')
const sshConfigPath = ref('/etc/ssh/sshd_config')

// Watch for SSH raw mode toggle
watch(sshRawMode, async (newVal) => {
  if (newVal && !sshRawConfig.value) {
    try {
      const response = await api.get('/ssh/config/raw')
      if (response.data.success) {
        sshRawConfig.value = response.data.data.content || ''
      }
    } catch (e) { toast.error('Failed to load raw SSH config') }
  }
})

const sshSettingDefinitions = [
  // Connection Settings
  { key: 'port', label: 'SSH Port', description: 'Port number for SSH connections', type: 'number', placeholder: '22', section: 'connection' },
  { key: 'listen_address', label: 'Listen Address', description: 'IP addresses to listen on (empty = all)', placeholder: '0.0.0.0', section: 'connection' },
  { key: 'login_grace_time', label: 'Login Grace Time', description: 'Time in seconds to authenticate before disconnect', type: 'number', placeholder: '120', section: 'connection' },
  { key: 'max_startups', label: 'Max Startups', description: 'Max concurrent unauthenticated connections', placeholder: '10:30:100', section: 'connection' },
  // Authentication Settings
  { key: 'permit_root_login', label: 'Root Login', description: 'Allow root user to login via SSH', type: 'select', options: [
    { value: 'yes', label: 'Yes' },
    { value: 'no', label: 'No' },
    { value: 'prohibit-password', label: 'Key Only (prohibit-password)' },
    { value: 'forced-commands-only', label: 'Forced Commands Only' },
  ], section: 'auth' },
  { key: 'password_authentication', label: 'Password Auth', description: 'Allow password-based authentication', type: 'toggle', section: 'auth' },
  { key: 'pubkey_authentication', label: 'Public Key Auth', description: 'Allow public key authentication', type: 'toggle', section: 'auth' },
  { key: 'permit_empty_passwords', label: 'Empty Passwords', description: 'Allow empty passwords (dangerous)', type: 'toggle', section: 'auth' },
  { key: 'max_auth_tries', label: 'Max Auth Tries', description: 'Maximum authentication attempts before disconnect', type: 'number', placeholder: '6', section: 'auth' },
  { key: 'max_sessions', label: 'Max Sessions', description: 'Maximum sessions per network connection', type: 'number', placeholder: '10', section: 'auth' },
  // Access Control
  { key: 'allow_users', label: 'Allow Users', description: 'Space-separated list of allowed usernames', placeholder: 'admin deployer', section: 'access' },
  { key: 'deny_users', label: 'Deny Users', description: 'Space-separated list of denied usernames', placeholder: 'guest test', section: 'access' },
  { key: 'allow_groups', label: 'Allow Groups', description: 'Space-separated list of allowed groups', placeholder: 'sudo wheel', section: 'access' },
  { key: 'deny_groups', label: 'Deny Groups', description: 'Space-separated list of denied groups', placeholder: '', section: 'access' },
  // Security Settings
  { key: 'use_dns', label: 'Use DNS', description: 'Lookup remote hostname (disable for faster logins)', type: 'toggle', section: 'security' },
  { key: 'tcp_keep_alive', label: 'TCP Keep Alive', description: 'Send TCP keepalive messages', type: 'toggle', section: 'security' },
  { key: 'client_alive_interval', label: 'Client Alive Interval', description: 'Seconds between keepalive messages (0=disabled)', type: 'number', placeholder: '300', section: 'security' },
  { key: 'client_alive_count_max', label: 'Client Alive Count', description: 'Max keepalive messages before disconnect', type: 'number', placeholder: '3', section: 'security' },
  // Features
  { key: 'x11_forwarding', label: 'X11 Forwarding', description: 'Allow X11 graphical forwarding', type: 'toggle', section: 'features' },
  { key: 'allow_tcp_forwarding', label: 'TCP Forwarding', description: 'Allow port forwarding tunnels', type: 'toggle', section: 'features' },
  { key: 'allow_agent_forwarding', label: 'Agent Forwarding', description: 'Allow SSH agent forwarding', type: 'toggle', section: 'features' },
  { key: 'gateway_ports', label: 'Gateway Ports', description: 'Allow remote hosts to connect to forwarded ports', type: 'toggle', section: 'features' },
  { key: 'banner', label: 'Banner File', description: 'Path to banner file shown before login', placeholder: '/etc/ssh/banner', section: 'features' },
]

const sshSections = [
  { id: 'connection', label: 'Connection', icon: 'cable' },
  { id: 'auth', label: 'Authentication', icon: 'key' },
  { id: 'access', label: 'Access Control', icon: 'shield_person' },
  { id: 'security', label: 'Security', icon: 'security' },
  { id: 'features', label: 'Features', icon: 'extension' },
]

const getSshSettingsBySection = (sectionId) => sshSettingDefinitions.filter(s => s.section === sectionId)

const sshHasChanges = computed(() => JSON.stringify(sshSettings.value) !== JSON.stringify(sshOriginalSettings.value))

const fetchSshSettings = async () => {
  sshLoading.value = true
  try {
    const response = await api.get('/system/ssh')
    if (response.data.success) {
      sshSettings.value = response.data.data
      sshOriginalSettings.value = { ...response.data.data }
    }
  } catch (e) {
    toast.error('Failed to load SSH settings')
  } finally {
    sshLoading.value = false
  }
}

const saveSshSettings = async () => {
  sshSaving.value = true
  try {
    const changes = {}
    for (const key in sshSettings.value) {
      if (sshSettings.value[key] !== sshOriginalSettings.value[key]) {
        changes[key] = sshSettings.value[key]
      }
    }
    const response = await api.put('/system/ssh', changes)
    if (response.data.success) {
      toast.success('SSH configuration updated. Changes applied.')
      sshOriginalSettings.value = { ...sshSettings.value }
      sshEditMode.value = false
    } else {
      toast.error(response.data.error || 'Failed to update SSH settings')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update SSH settings')
  } finally {
    sshSaving.value = false
  }
}

const cancelSshEdit = () => {
  sshSettings.value = { ...sshOriginalSettings.value }
  sshEditMode.value = false
}

// ============================================
// Swap State & Logic
// ============================================
const swapLoading = ref(true)
const swapSaving = ref(false)
const swapInfo = ref(null)
const showCreateSwapModal = ref(false)
const newSwap = ref({ size: '2G', path: '/swapfile' })

const fetchSwapInfo = async () => {
  swapLoading.value = true
  try {
    const response = await api.get('/system/swap')
    if (response.data.success) {
      swapInfo.value = response.data.data
    }
  } catch (e) {
    toast.error('Failed to load swap information')
  } finally {
    swapLoading.value = false
  }
}

const createSwap = async () => {
  swapSaving.value = true
  try {
    const response = await api.post('/system/swap', newSwap.value)
    if (response.data.success) {
      toast.success('Swap file created successfully')
      showCreateSwapModal.value = false
      await fetchSwapInfo()
    } else {
      toast.error(response.data.error || 'Failed to create swap')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create swap')
  } finally {
    swapSaving.value = false
  }
}

const updateSwappiness = async (value) => {
  swapSaving.value = true
  try {
    const response = await api.post('/system/swappiness', { value })
    if (response.data.success) {
      toast.success('Swappiness updated')
      swapInfo.value.swappiness = value
    } else {
      toast.error(response.data.error || 'Failed to update swappiness')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update swappiness')
  } finally {
    swapSaving.value = false
  }
}

// ============================================
// MOTD State & Logic
// ============================================
const motdLoading = ref(false)
const motdSaving = ref(false)
const motdData = ref(null)
const motdContent = ref('')
const motdEditMode = ref('static') // 'static' or 'scripts'
const scriptContents = ref({}) // Track edited content for each script
const expandedScripts = ref({}) // Track which scripts are expanded

const fetchMotd = async () => {
  motdLoading.value = true
  try {
    const response = await api.get('/system/motd')
    if (response.data.success) {
      motdData.value = response.data.data
      
      // Initialize script contents
      if (response.data.data.scripts) {
        const contents = {}
        response.data.data.scripts.forEach(s => {
          contents[s.name] = s.content
        })
        scriptContents.value = contents
        
        // Load panel-motd script content into editor (strip the header)
        const panelScript = response.data.data.scripts.find(s => s.name === '50-panel-motd')
        if (panelScript && panelScript.content) {
          // Remove the #!/bin/bash header and comments we added
          let content = panelScript.content
          content = content.replace(/^#!\/bin\/bash\n/, '')
          content = content.replace(/^# Custom MOTD.*\n/m, '')
          content = content.replace(/^# Last updated:.*\n/m, '')
          motdContent.value = content.trim()
        } else {
          motdContent.value = ''
        }
      } else {
        motdContent.value = ''
      }
    }
  } catch (e) {
    toast.error('Failed to load MOTD')
  } finally {
    motdLoading.value = false
  }
}

const saveMotd = async () => {
  motdSaving.value = true
  try {
    // Save content directly as a bash script - user builds the script themselves
    // with the help of insert buttons for dynamic blocks
    const scriptContent = `#!/bin/bash
# Custom MOTD - Managed by VPS Admin Panel
# Last updated: ${new Date().toISOString().split('T')[0]}

${motdContent.value}
`
    
    // Save as dynamic script
    const response = await api.put('/system/motd', { 
      type: 'script', 
      name: '50-panel-motd',
      content: scriptContent
    })
    
    // Also clear static MOTD to avoid showing raw text (send space to pass validation)
    await api.put('/system/motd', { 
      type: 'static', 
      content: ' ' 
    })
    
    if (response.data.success) {
      toast.success('MOTD script installed! Will show on next SSH login.')
      await fetchMotd()
    } else {
      toast.error(response.data.error || 'Failed to save MOTD')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save MOTD')
  } finally {
    motdSaving.value = false
  }
}

const saveScript = async (scriptName) => {
  motdSaving.value = true
  try {
    const response = await api.put('/system/motd', { 
      type: 'script', 
      name: scriptName, 
      content: scriptContents.value[scriptName] 
    })
    if (response.data.success) {
      toast.success(`Script ${scriptName} saved successfully`)
      await fetchMotd()
    } else {
      toast.error(response.data.error || 'Failed to save script')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save script')
  } finally {
    motdSaving.value = false
  }
}

const disableCyberpanelMotd = async () => {
  motdSaving.value = true
  try {
    const response = await api.put('/system/motd', { type: 'disable_cyberpanel' })
    if (response.data.success) {
      toast.success('CyberPanel MOTD disabled')
      await fetchMotd()
    } else {
      toast.error(response.data.error || 'Failed to disable CyberPanel MOTD')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to disable CyberPanel MOTD')
  } finally {
    motdSaving.value = false
  }
}

const toggleScript = (name) => {
  expandedScripts.value[name] = !expandedScripts.value[name]
}

const switchToStaticMode = () => {
  motdEditMode.value = 'static'
  motdContent.value = motdData.value?.static_motd || ''
}

// MOTD Dynamic Variables
const motdVariables = [
  { label: 'Hostname', code: '$(hostname)', icon: 'dns' },
  { label: 'Date/Time', code: '$(date)', icon: 'schedule' },
  { label: 'Uptime', code: '$(uptime -p)', icon: 'timer' },
  { label: 'Load Average', code: '$(cat /proc/loadavg | cut -d" " -f1-3)', icon: 'speed' },
  { label: 'Memory Usage', code: '$(free -h | awk \'/Mem:/ {print $3\"/\"$2}\')', icon: 'memory' },
  { label: 'Disk Usage', code: '$(df -h / | awk \'NR==2 {print $3\"/\"$2\" (\"$5\")\"}\')', icon: 'storage' },
  { label: 'IP Address', code: '$(hostname -I | awk \'{print $1}\')', icon: 'language' },
  { label: 'Kernel', code: '$(uname -r)', icon: 'terminal' },
  { label: 'Users Logged In', code: '$(who | wc -l)', icon: 'group' },
  { label: 'CPU Usage', code: '$(top -bn1 | grep "Cpu(s)" | awk \'{print $2}\')', icon: 'developer_board' },
]

// MOTD Colors - using $'...' syntax for bash escape sequences
const motdColors = [
  { label: 'Cyan', code: "$'\\e[38;5;49m'", endCode: "$'\\e[0m'", color: '#00d9a0' },
  { label: 'Green', code: "$'\\e[0;32m'", endCode: "$'\\e[0m'", color: '#22c55e' },
  { label: 'Bright Green', code: "$'\\e[0;92m'", endCode: "$'\\e[0m'", color: '#4ade80' },
  { label: 'Yellow', code: "$'\\e[0;33m'", endCode: "$'\\e[0m'", color: '#eab308' },
  { label: 'Red', code: "$'\\e[0;31m'", endCode: "$'\\e[0m'", color: '#ef4444' },
  { label: 'Blue', code: "$'\\e[0;34m'", endCode: "$'\\e[0m'", color: '#3b82f6' },
  { label: 'Purple', code: "$'\\e[0;35m'", endCode: "$'\\e[0m'", color: '#a855f7' },
  { label: 'White', code: "$'\\e[1;37m'", endCode: "$'\\e[0m'", color: '#ffffff' },
]

const selectedColor = ref(motdColors[0])

const insertColorStart = () => {
  insertMotdVariable(selectedColor.value.code)
}

const insertColorEnd = () => {
  insertMotdVariable("$'\\e[0m'")
}

// Service Status Script Template
const serviceStatusScript = `#!/bin/bash
# Service Status MOTD Script
GREEN='\\033[0;32m'
RED='\\033[0;31m'
YELLOW='\\033[1;33m'
NC='\\033[0m'

check_service() {
    local name="\$1"
    local service="\$2"
    local extra="\$3"
    if systemctl is-active --quiet "\$service" 2>/dev/null; then
        printf "  %-18s \${GREEN}RUNNING\${NC}  %s\\n" "\$name" "\$extra"
    else
        printf "  %-18s \${RED}STOPPED\${NC}\\n" "\$name"
    fi
}

echo ""
echo "  ------------------------------------------"

# FirewallD/UFW
if systemctl is-active --quiet firewalld 2>/dev/null; then
    PORTS=\$(firewall-cmd --list-ports 2>/dev/null | wc -w)
    printf "  %-18s \${GREEN}RUNNING\${NC}  %s ports open\\n" "FirewallD" "\$PORTS"
elif systemctl is-active --quiet ufw 2>/dev/null; then
    printf "  %-18s \${GREEN}RUNNING\${NC}\\n" "UFW"
fi

# Fail2Ban
if systemctl is-active --quiet fail2ban; then
    JAILS=\$(fail2ban-client status 2>/dev/null | grep "Number of jail" | awk '{print \$NF}')
    printf "  %-18s \${GREEN}RUNNING\${NC}  %s jails\\n" "Fail2Ban" "\$JAILS"
fi

# OpenLiteSpeed
if systemctl is-active --quiet lsws || systemctl is-active --quiet lshttpd; then
    WORKERS=\$(ps aux | grep -c "[l]shttpd - #")
    printf "  %-18s \${GREEN}RUNNING\${NC}  %s workers\\n" "OpenLiteSpeed" "\$WORKERS"
fi

# SSH
SSH_PORT=\$(grep "^Port" /etc/ssh/sshd_config 2>/dev/null | awk '{print \$2}')
SSH_PORT=\${SSH_PORT:-22}
if grep -q "PasswordAuthentication no" /etc/ssh/sshd_config 2>/dev/null; then
    printf "  %-18s \${GREEN}HARDENED\${NC} port %s, key-only\\n" "SSH" "\$SSH_PORT"
else
    printf "  %-18s \${GREEN}RUNNING\${NC}  port %s\\n" "SSH" "\$SSH_PORT"
fi

# Dovecot
check_service "Dovecot" "dovecot" "imap"

# Postfix
if systemctl is-active --quiet postfix; then
    QUEUE=\$(mailq 2>/dev/null | tail -1 | grep -o '[0-9]* Request' | awk '{print \$1}')
    [ -z "\$QUEUE" ] && QUEUE=0
    [ "\$QUEUE" -eq 0 ] && printf "  %-18s \${GREEN}RUNNING\${NC}  queue empty\\n" "Postfix" || printf "  %-18s \${GREEN}RUNNING\${NC}  %s queued\\n" "Postfix" "\$QUEUE"
fi

# PowerDNS
if systemctl is-active --quiet pdns; then
    ZONES=\$(pdnsutil list-all-zones 2>/dev/null | wc -l)
    printf "  %-18s \${GREEN}RUNNING\${NC}  %s zones\\n" "PowerDNS" "\$ZONES"
fi

# MariaDB
check_service "MariaDB" "mariadb" "localhost only"

# Redis
check_service "Redis" "redis-server" "localhost only"

echo "  ------------------------------------------"
echo ""
`

const motdStaticTextarea = ref(null)
const motdScriptTextareas = ref({})

const insertMotdVariable = (code) => {
  if (motdEditMode.value === 'static') {
    // For static MOTD, insert at cursor position
    const textarea = motdStaticTextarea.value
    if (textarea) {
      const start = textarea.selectionStart
      const end = textarea.selectionEnd
      const text = motdContent.value
      motdContent.value = text.substring(0, start) + code + text.substring(end)
      // Set cursor position after inserted text
      nextTick(() => {
        textarea.selectionStart = textarea.selectionEnd = start + code.length
        textarea.focus()
      })
    } else {
      motdContent.value += code
    }
  }
}

const insertScriptVariable = (scriptName, variable) => {
  const currentContent = scriptContents.value[scriptName] || ''
  const echoLine = `echo "${variable.label}: ${variable.code}"\n`
  scriptContents.value[scriptName] = currentContent + echoLine
}

// Insertable dynamic blocks - these are bash code snippets
// Using \e instead of \033 for better compatibility
const dynamicBlocks = [
  {
    label: 'Service Status',
    icon: 'monitor_heart',
    description: 'Shows status of all services with colors',
    code: `# Service Status
G=$'\\e[0;32m'; R=$'\\e[0;31m'; N=$'\\e[0m'
chk() { systemctl is-active --quiet "$1" 2>/dev/null && printf "  %-14s \${G}RUNNING\${N}\\n" "$2" || printf "  %-14s \${R}STOPPED\${N}\\n" "$2"; }
chk firewalld "FirewallD"
chk fail2ban "Fail2Ban"
chk lshttpd "OpenLiteSpeed"
chk dovecot "Dovecot"
chk postfix "Postfix"
chk pdns "PowerDNS"
chk mariadb "MariaDB"
chk redis-server "Redis"
`
  },
  {
    label: 'System Resources',
    icon: 'memory',
    description: 'CPU, Memory, Disk usage',
    code: `# System Resources
echo "  CPU Load:  $(cat /proc/loadavg | cut -d' ' -f1-3)"
echo "  Memory:    $(free -h | awk '/Mem:/ {print $3"/"$2}')"
echo "  Disk:      $(df -h / | awk 'NR==2 {print $3"/"$2" ("$5")"}')"
echo "  Uptime:    $(uptime -p | sed 's/up //')"
`
  },
  {
    label: 'Server Info',
    icon: 'dns',
    description: 'Hostname, IP, Kernel',
    code: `# Server Info
echo "  Hostname:  $(hostname)"
echo "  IP:        $(hostname -I | awk '{print $1}')"
echo "  Kernel:    $(uname -r)"
echo "  Date:      $(date '+%Y-%m-%d %H:%M')"
`
  },
  {
    label: 'User Sessions',
    icon: 'group',
    description: 'Logged in users',
    code: `# User Sessions
echo "  Users:     $(who | wc -l) logged in"
echo "  Last:      $(last -1 -R | head -1 | awk '{print $1" from "$3}')"
`
  },
  {
    label: 'Colored Title',
    icon: 'title',
    description: 'Cyan colored text block',
    code: `# Colored Title
C=$'\\e[38;5;49m'; N=$'\\e[0m'
echo -e "\${C}////////////////////////////////\${N}"
echo -e "\${C}     YOUR TITLE HERE\${N}"
echo -e "\${C}////////////////////////////////\${N}"
`
  },
  {
    label: 'Separator Line',
    icon: 'horizontal_rule',
    description: 'Horizontal divider',
    code: `echo "  ----------------------------------------"
`
  }
]

const insertDynamicBlock = (code) => {
  // Insert at cursor position in the textarea
  const textarea = motdStaticTextarea.value
  if (textarea) {
    const start = textarea.selectionStart
    const end = textarea.selectionEnd
    const text = motdContent.value
    motdContent.value = text.substring(0, start) + code + text.substring(end)
    nextTick(() => {
      textarea.selectionStart = textarea.selectionEnd = start + code.length
      textarea.focus()
    })
  } else {
    motdContent.value += code
  }
}

// ============================================
// Templates State & Logic
// ============================================
const templatesLoading = ref(false)
const templatesSaving = ref(false)
const templatesData = ref(null)
const selectedTemplate = ref(null)
const templateContent = ref('')
const showTemplateEditor = ref(false)
const templatePreview = ref(false)
const templateDragOver = ref(false)

// Computed properties to filter templates
const errorTemplates = computed(() => {
  if (!templatesData.value?.templates) return []
  return Object.entries(templatesData.value.templates)
    .filter(([id]) => id.startsWith('error_'))
    .map(([id, template]) => ({ ...template, id }))
})

const siteTemplates = computed(() => {
  if (!templatesData.value?.templates) return []
  return Object.entries(templatesData.value.templates)
    .filter(([id]) => id.startsWith('site_'))
    .map(([id, template]) => ({ ...template, id }))
})

const fetchTemplates = async () => {
  templatesLoading.value = true
  try {
    const response = await api.get('/system/templates')
    if (response.data.success) {
      templatesData.value = response.data.data
    }
  } catch (e) {
    toast.error('Failed to load templates')
  } finally {
    templatesLoading.value = false
  }
}

const editTemplate = async (template) => {
  selectedTemplate.value = template
  templateContent.value = '' // Clear first
  templatePreview.value = false
  showTemplateEditor.value = true
  templatesLoading.value = true
  try {
    const response = await api.get(`/system/templates/${template.id}`)
    if (response.data.success) {
      templateContent.value = response.data.data.content || ''
    }
  } catch (e) {
    toast.error('Failed to load template')
  } finally {
    templatesLoading.value = false
  }
}

const saveTemplate = async () => {
  templatesSaving.value = true
  try {
    const response = await api.put(`/system/templates/${selectedTemplate.value.id}`, {
      content: templateContent.value
    })
    if (response.data.success) {
      toast.success('Template saved successfully')
      showTemplateEditor.value = false
      await fetchTemplates()
    } else {
      toast.error(response.data.error || 'Failed to save template')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save template')
  } finally {
    templatesSaving.value = false
  }
}

const resetTemplate = async () => {
  // Load default template
  templatesLoading.value = true
  try {
    const response = await api.get(`/system/templates/${selectedTemplate.value.id}?default=1`)
    if (response.data.success) {
      templateContent.value = response.data.data.content || ''
    }
  } catch (e) {
    toast.error('Failed to load default template')
  } finally {
    templatesLoading.value = false
  }
}

// Drag & drop HTML file
const handleTemplateDragOver = (e) => {
  e.preventDefault()
  templateDragOver.value = true
}

const handleTemplateDragLeave = () => {
  templateDragOver.value = false
}

const handleTemplateDrop = async (e) => {
  e.preventDefault()
  templateDragOver.value = false
  
  const files = e.dataTransfer?.files
  if (!files?.length) return
  
  const file = files[0]
  if (!file.name.endsWith('.html') && !file.name.endsWith('.htm') && file.type !== 'text/html') {
    toast.error('Please drop an HTML file')
    return
  }
  
  try {
    const content = await file.text()
    templateContent.value = content
    toast.success(`Loaded ${file.name}`)
  } catch (e) {
    toast.error('Failed to read file')
  }
}

const handleTemplateFileSelect = async (e) => {
  const file = e.target?.files?.[0]
  if (!file) return
  
  try {
    const content = await file.text()
    templateContent.value = content
    toast.success(`Loaded ${file.name}`)
  } catch (e) {
    toast.error('Failed to read file')
  }
  
  // Reset input
  e.target.value = ''
}

// Template Deployment
const showDeployModal = ref(false)
const deployingTemplate = ref(null)
const deploying = ref(false)
const deploySkipExisting = ref(true)
const deployResults = ref(null)
const deploySites = ref([])
const deploySitesLoading = ref(false)
const selectedDeploySites = ref([])
const deployMode = ref('all') // 'all' or 'selected'

// Bulk Revert
const showBulkRevertModal = ref(false)
const bulkReverting = ref(false)
const bulkRevertResults = ref(null)
const revertingSite = ref(null)

// Revert single site
const revertSingleSite = async (domain) => {
  revertingSite.value = domain
  try {
    const response = await api.post(`/system/templates/revert/${domain}`, {
      filename: 'index.html',
      remove_backup: true
    })
    if (response.data.success) {
      toast.success(`Reverted ${domain} to original`)
      // Update the site in the list
      const site = deploySites.value.find(s => s.domain === domain)
      if (site) {
        site.has_template_backup = false
        site.backup_count = 0
        site.latest_backup = null
        site.template_type = null
        site.deployed_at = null
      }
    } else {
      toast.error(response.data.error || `Failed to revert ${domain}`)
    }
  } catch (e) {
    toast.error(e.response?.data?.error || `Failed to revert ${domain}`)
  } finally {
    revertingSite.value = null
  }
}

const bulkRevertTemplates = async () => {
  const sitesWithBackups = deploySites.value.filter(s => s.has_template_backup)
  if (!sitesWithBackups.length) return
  
  bulkReverting.value = true
  bulkRevertResults.value = null
  const reverted = []
  const failed = []
  
  for (const site of sitesWithBackups) {
    try {
      const response = await api.post(`/system/templates/revert/${site.domain}`, {
        filename: 'index.html',
        remove_backup: true
      })
      if (response.data.success) {
        reverted.push(site.domain)
      } else {
        failed.push(site.domain)
      }
    } catch (e) {
      failed.push(site.domain)
    }
  }
  
  bulkRevertResults.value = { reverted, failed }
  
  if (reverted.length > 0) {
    toast.success(`Reverted ${reverted.length} site(s) to original`)
    // Refresh the sites list to update backup status
    deploySitesLoading.value = true
    try {
      const response = await api.get('/system/templates/sites')
      if (response.data.success) {
        deploySites.value = response.data.data.sites || []
      }
    } catch (e) { }
    deploySitesLoading.value = false
  }
  if (failed.length > 0) {
    toast.error(`Failed to revert ${failed.length} site(s)`)
  }
  
  bulkReverting.value = false
  showBulkRevertModal.value = false
}

// Template type helpers
const getTemplateTypeLabel = (type) => {
  const labels = {
    'site_placeholder': 'Placeholder',
    'site_coming_soon': 'Coming Soon',
    'site_maintenance': 'Maintenance',
  }
  return labels[type] || type || 'Template'
}

const getTemplateTypeBadgeClass = (type) => {
  const classes = {
    'site_placeholder': 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-400',
    'site_coming_soon': 'bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-400',
    'site_maintenance': 'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-400',
  }
  return classes[type] || 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400'
}

const formatRelativeTime = (dateStr) => {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now - date
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)
  
  if (diffMins < 1) return 'just now'
  if (diffMins < 60) return `${diffMins}m ago`
  if (diffHours < 24) return `${diffHours}h ago`
  if (diffDays < 7) return `${diffDays}d ago`
  return date.toLocaleDateString()
}

const openDeployModal = async (template) => {
  deployingTemplate.value = template
  deployResults.value = null
  deploySkipExisting.value = true
  deployMode.value = 'all'
  selectedDeploySites.value = []
  showDeployModal.value = true
  
  // Always refresh sites list to get latest template info
  deploySitesLoading.value = true
  try {
    const response = await api.get('/system/templates/sites')
    if (response.data.success) {
      deploySites.value = response.data.data.sites || []
    }
  } catch (e) {
    console.error('Failed to load sites', e)
  } finally {
    deploySitesLoading.value = false
  }
}

const toggleSiteSelection = (domain) => {
  const idx = selectedDeploySites.value.indexOf(domain)
  if (idx === -1) {
    selectedDeploySites.value.push(domain)
  } else {
    selectedDeploySites.value.splice(idx, 1)
  }
}

const selectAllSites = () => {
  selectedDeploySites.value = deploySites.value.map(s => s.domain)
}

const deselectAllSites = () => {
  selectedDeploySites.value = []
}

const deployToSites = async () => {
  if (!deployingTemplate.value) return
  
  deploying.value = true
  deployResults.value = null
  
  try {
    if (deployMode.value === 'all') {
      // Deploy to all sites
      const response = await api.post(`/system/templates/${deployingTemplate.value.id}/deploy-all`, {
        skip_existing: deploySkipExisting.value,
        filename: 'index.html'
      })
      
      if (response.data.success) {
        deployResults.value = response.data.data
        toast.success(response.data.message || 'Template deployed successfully')
      } else {
        toast.error(response.data.error || 'Failed to deploy template')
      }
    } else {
      // Deploy to selected sites only
      const deployed = []
      const failed = []
      
      for (const domain of selectedDeploySites.value) {
        try {
          const response = await api.post(`/system/templates/${deployingTemplate.value.id}/apply`, {
            domain: domain,
            filename: 'index.html'
          })
          if (response.data.success) {
            deployed.push(domain)
          } else {
            failed.push(domain)
          }
        } catch (e) {
          failed.push(domain)
        }
      }
      
      deployResults.value = {
        deployed,
        failed,
        skipped: [],
        total_sites: selectedDeploySites.value.length
      }
      
      if (deployed.length > 0) {
        toast.success(`Template deployed to ${deployed.length} site(s)`)
      }
      if (failed.length > 0) {
        toast.error(`Failed to deploy to ${failed.length} site(s)`)
      }
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to deploy template')
  } finally {
    deploying.value = false
  }
}

// ============================================
// Reboot Logic
// ============================================
const showRebootModal = ref(false)
const rebooting = ref(false)

const rebootServer = async () => {
  rebooting.value = true
  try {
    const response = await api.post('/system/reboot', { delay: 0 })
    if (response.data.success) {
      toast.success('Server is rebooting...')
      showRebootModal.value = false
    } else {
      toast.error(response.data.error || 'Failed to reboot server')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to reboot server')
  } finally {
    rebooting.value = false
  }
}

// ============================================
// OpenLiteSpeed State & Logic
// ============================================
const olsLoading = ref(true)
const olsSaving = ref(false)
const olsStatus = ref(null)
const olsSettings = ref({})
const olsOriginalSettings = ref({})
const olsEditMode = ref(false)
const olsRawMode = ref(false)
const olsRawConfig = ref('')
const olsConfigPath = ref('/usr/local/lsws/conf/httpd_config.conf')

const olsSettingDefinitions = [
  { key: 'serverName', label: 'Server Name', description: 'Server hostname identifier', placeholder: 'server1', section: 'server' },
  { key: 'adminEmails', label: 'Admin Email', description: 'Administrator email address', placeholder: 'admin@example.com', section: 'server' },
  { key: 'tuning_maxConnections', label: 'Max Connections', description: 'Maximum concurrent connections', placeholder: '2000', section: 'tuning' },
  { key: 'tuning_maxSSLConnections', label: 'Max SSL Connections', description: 'Maximum concurrent SSL connections', placeholder: '1000', section: 'tuning' },
  { key: 'tuning_connTimeout', label: 'Connection Timeout', description: 'Connection timeout in seconds', placeholder: '300', section: 'tuning' },
  { key: 'tuning_maxKeepAliveReq', label: 'Max Keep-Alive Requests', description: 'Maximum requests per keep-alive connection', placeholder: '1000', section: 'tuning' },
  { key: 'tuning_keepAliveTimeout', label: 'Keep-Alive Timeout', description: 'Keep-alive connection timeout in seconds', placeholder: '5', section: 'tuning' },
  { key: 'tuning_enableGzipCompress', label: 'Enable GZIP', description: 'Enable GZIP compression for responses', placeholder: '1', type: 'toggle', section: 'compression' },
  { key: 'tuning_gzipCompressLevel', label: 'GZIP Level', description: 'GZIP compression level (1-9)', placeholder: '6', section: 'compression' },
  { key: 'tuning_enableBrCompress', label: 'Enable Brotli', description: 'Enable Brotli compression', placeholder: '1', type: 'toggle', section: 'compression' },
]

const olsSections = [
  { id: 'server', label: 'Server Settings', icon: 'dns' },
  { id: 'tuning', label: 'Performance Tuning', icon: 'speed' },
  { id: 'compression', label: 'Compression', icon: 'compress' },
]

const getSettingsBySection = (sectionId) => olsSettingDefinitions.filter(s => s.section === sectionId)
const olsHasChanges = computed(() => JSON.stringify(olsSettings.value) !== JSON.stringify(olsOriginalSettings.value))

const fetchOlsStatus = async () => {
  try {
    const response = await api.get('/ols/status')
    if (response.data.success) olsStatus.value = response.data.data
  } catch (e) { console.error('Failed to fetch OLS status', e) }
}

const fetchOlsSettings = async () => {
  olsLoading.value = true
  try {
    const response = await api.get('/ols/settings')
    if (response.data.success) {
      olsSettings.value = response.data.data.settings || {}
      olsOriginalSettings.value = { ...olsSettings.value }
    }
  } catch (e) {
    toast.error('Failed to load OpenLiteSpeed settings')
  } finally {
    olsLoading.value = false
  }
}

const saveOlsSettings = async () => {
  olsSaving.value = true
  try {
    let response
    if (olsRawMode.value) {
      // Save raw config content
      response = await api.put('/ols/config/raw', { content: olsRawConfig.value })
      if (response.data.success) {
        toast.success('OpenLiteSpeed configuration saved. Restart to apply changes.')
        olsRawMode.value = false
        // Refresh settings from the saved config
        await fetchOlsSettings()
      } else {
        toast.error(response.data.error || 'Failed to save configuration')
      }
    } else {
      // Save structured settings
      response = await api.put('/ols/settings', { settings: olsSettings.value })
      if (response.data.success) {
        toast.success('OpenLiteSpeed settings saved. Restart to apply changes.')
        olsOriginalSettings.value = { ...olsSettings.value }
        olsEditMode.value = false
      } else {
        toast.error(response.data.error || 'Failed to save settings')
      }
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save settings')
  } finally {
    olsSaving.value = false
  }
}

const cancelOlsEdit = () => { olsSettings.value = { ...olsOriginalSettings.value }; olsEditMode.value = false }
const restartOls = async () => {
  olsSaving.value = true
  try {
    const response = await api.post('/ols/restart')
    if (response.data.success) { toast.success('OpenLiteSpeed restarted successfully'); await fetchOlsStatus() }
    else toast.error(response.data.error || 'Failed to restart OpenLiteSpeed')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to restart OpenLiteSpeed') }
  finally { olsSaving.value = false }
}
const reloadOls = async () => {
  olsSaving.value = true
  try {
    const response = await api.post('/ols/reload')
    if (response.data.success) toast.success('OpenLiteSpeed reloaded successfully')
    else toast.error(response.data.error || 'Failed to reload OpenLiteSpeed')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to reload OpenLiteSpeed') }
  finally { olsSaving.value = false }
}

const fetchOlsRawConfig = async () => {
  try {
    const response = await api.get('/ols/config/raw')
    if (response.data.success) {
      olsRawConfig.value = response.data.data.content || ''
    }
  } catch (e) { toast.error('Failed to load raw config') }
}

// Watch for raw mode toggle to load raw config
watch(olsRawMode, async (newVal) => {
  if (newVal && !olsRawConfig.value) {
    await fetchOlsRawConfig()
  }
})

// ============================================
// OLS extprocessor Calculator
// ============================================
const showOlsCalculator = ref(false)
const olsCalculatorLoading = ref(false)
const olsCalculatorData = ref(null)
const olsCalculatorCopied = ref(false)
const olsSimulateMode = ref(false)
const olsCustomCores = ref(4)
const olsCustomRamGB = ref(8)
const olsCustomVhosts = ref(10)

const openOlsCalculator = async () => {
  showOlsCalculator.value = true
  olsCalculatorLoading.value = true
  olsCalculatorData.value = null
  olsSimulateMode.value = false
  
  try {
    const response = await api.get('/ols/calculator')
    if (response.data.success) {
      olsCalculatorData.value = response.data.data
      // Set custom inputs to current values
      olsCustomCores.value = response.data.data.system.cpu_cores
      olsCustomRamGB.value = Math.round(response.data.data.system.total_ram_mb / 1024 * 10) / 10
      olsCustomVhosts.value = response.data.data.vhosts.count
    } else {
      toast.error(response.data.error || 'Failed to load calculator data')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to load calculator data')
  } finally {
    olsCalculatorLoading.value = false
  }
}

const simulateOlsCalculator = async () => {
  olsCalculatorLoading.value = true
  olsSimulateMode.value = true
  
  try {
    const response = await api.get('/ols/calculator', {
      params: {
        custom_cores: olsCustomCores.value,
        custom_ram_gb: olsCustomRamGB.value,
        custom_vhosts: olsCustomVhosts.value
      }
    })
    if (response.data.success) {
      olsCalculatorData.value = response.data.data
    } else {
      toast.error(response.data.error || 'Failed to simulate')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to simulate')
  } finally {
    olsCalculatorLoading.value = false
  }
}

const copyCalculatorConfig = async () => {
  if (!olsCalculatorData.value?.config_template) return
  
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(olsCalculatorData.value.config_template)
    } else {
      const textArea = document.createElement('textarea')
      textArea.value = olsCalculatorData.value.config_template
      textArea.style.position = 'fixed'
      textArea.style.left = '-9999px'
      document.body.appendChild(textArea)
      textArea.select()
      document.execCommand('copy')
      document.body.removeChild(textArea)
    }
    olsCalculatorCopied.value = true
    toast.success('Configuration copied to clipboard')
    setTimeout(() => { olsCalculatorCopied.value = false }, 2000)
  } catch (e) {
    toast.error('Failed to copy to clipboard')
  }
}

// ============================================
// PHP State & Logic
// ============================================
const phpLoading = ref(true)
const phpSaving = ref(false)
const phpVersions = ref([])
const filteredPhpVersions = computed(() => phpVersions.value.filter(v => v.version !== '8.4'))
const selectedVersion = ref(null)
const phpSettings = ref({})
const phpOriginalSettings = ref({})
const phpEditMode = ref(false)
const phpRawMode = ref(false)
const phpRawConfig = ref('')
const phpRawLoading = ref(false)
const phpSelectedConfigFile = ref('')

// Generate PHP config files list from available versions
// Note: Only litespeed php.ini exists on most LiteSpeed setups, CLI php.ini typically doesn't exist
const phpConfigFiles = computed(() => {
  const files = []
  for (const php of filteredPhpVersions.value) {
    const ver = php.version
    const handler = `lsphp${ver.replace('.', '')}`
    files.push({
      path: `/usr/local/lsws/${handler}/etc/php/${ver}/litespeed/php.ini`,
      label: `php.ini (PHP ${ver}) - LiteSpeed configuration`,
      description: 'Main PHP configuration for LiteSpeed',
      version: ver
    })
  }
  return files
})

const phpConfigPath = computed(() => phpSelectedConfigFile.value || (selectedVersion.value ? `/usr/local/lsws/lsphp${selectedVersion.value.replace('.', '')}/etc/php/${selectedVersion.value}/litespeed/php.ini` : ''))

// Load PHP raw config for selected file
const loadPhpRawConfig = async (filePath) => {
  if (!filePath || !selectedVersion.value) return
  phpRawLoading.value = true
  try {
    const response = await api.get(`/php/${selectedVersion.value}/config/raw`, {
      params: { file: filePath }
    })
    if (response.data.success) {
      phpRawConfig.value = response.data.data.content || ''
    } else {
      toast.error(response.data.error || 'Failed to load PHP config')
    }
  } catch (e) {
    toast.error('Failed to load PHP config file')
  } finally {
    phpRawLoading.value = false
  }
}

// Watch for PHP config file selection change
watch(phpSelectedConfigFile, async (newVal) => {
  if (newVal && phpRawMode.value) {
    await loadPhpRawConfig(newVal)
  }
})

// Watch for PHP raw mode toggle
watch(phpRawMode, async (newVal) => {
  if (newVal) {
    // Set default file if not set
    if (!phpSelectedConfigFile.value && selectedVersion.value) {
      phpSelectedConfigFile.value = `/usr/local/lsws/lsphp${selectedVersion.value.replace('.', '')}/etc/php/${selectedVersion.value}/litespeed/php.ini`
    }
    if (phpSelectedConfigFile.value) {
      await loadPhpRawConfig(phpSelectedConfigFile.value)
    }
  }
})

const phpSettingDefinitions = [
  // Core Settings
  { key: 'memory_limit', label: 'Memory Limit', description: 'Maximum memory a script can consume', placeholder: '256M', section: 'core' },
  { key: 'max_execution_time', label: 'Max Execution Time', description: 'Maximum time a script can run (seconds)', placeholder: '30', section: 'core' },
  { key: 'max_input_time', label: 'Max Input Time', description: 'Maximum time to parse input data (seconds)', placeholder: '60', section: 'core' },
  { key: 'upload_max_filesize', label: 'Upload Max Filesize', description: 'Maximum size of uploaded files', placeholder: '64M', section: 'core' },
  { key: 'post_max_size', label: 'Post Max Size', description: 'Maximum size of POST data', placeholder: '64M', section: 'core' },
  { key: 'max_input_vars', label: 'Max Input Vars', description: 'Maximum number of input variables', placeholder: '1000', section: 'core' },
  { key: 'max_file_uploads', label: 'Max File Uploads', description: 'Maximum simultaneous file uploads', placeholder: '20', section: 'core' },
  { key: 'date.timezone', label: 'Timezone', description: 'Default timezone for date functions', placeholder: 'Europe/Budapest', section: 'core' },
  // Error Handling
  { key: 'display_errors', label: 'Display Errors', description: 'Show errors on screen (disable in production)', type: 'toggle', section: 'errors' },
  { key: 'display_startup_errors', label: 'Display Startup Errors', description: 'Show errors during PHP startup', type: 'toggle', section: 'errors' },
  { key: 'log_errors', label: 'Log Errors', description: 'Log errors to file', type: 'toggle', section: 'errors' },
  { key: 'error_reporting', label: 'Error Reporting', description: 'Error reporting level', placeholder: 'E_ALL', section: 'errors' },
  { key: 'error_log', label: 'Error Log Path', description: 'Path to error log file', placeholder: '/var/log/php_errors.log', section: 'errors' },
  // Security
  { key: 'expose_php', label: 'Expose PHP', description: 'Show PHP version in headers (disable for security)', type: 'toggle', section: 'security' },
  { key: 'allow_url_fopen', label: 'Allow URL Fopen', description: 'Allow opening URLs as files', type: 'toggle', section: 'security' },
  { key: 'allow_url_include', label: 'Allow URL Include', description: 'Allow including remote files (dangerous)', type: 'toggle', section: 'security' },
  { key: 'open_basedir', label: 'Open Basedir', description: 'Restrict file operations to paths (empty=no restriction)', placeholder: '/var/www:/tmp', section: 'security' },
  { key: 'disable_functions', label: 'Disable Functions', description: 'Comma-separated list of disabled functions', placeholder: 'exec,passthru,shell_exec,system,proc_open,popen', section: 'security' },
  // OPCache
  { key: 'opcache.enable', label: 'Enable OPCache', description: 'Enable the opcode cache', type: 'toggle', section: 'opcache' },
  { key: 'opcache.memory_consumption', label: 'Memory (MB)', description: 'Memory allocated to OPCache', placeholder: '128', section: 'opcache' },
  { key: 'opcache.interned_strings_buffer', label: 'Interned Strings Buffer', description: 'Memory for interned strings (MB)', placeholder: '8', section: 'opcache' },
  { key: 'opcache.max_accelerated_files', label: 'Max Accelerated Files', description: 'Maximum number of cached files', placeholder: '10000', section: 'opcache' },
  { key: 'opcache.validate_timestamps', label: 'Validate Timestamps', description: 'Check file modification times', type: 'toggle', section: 'opcache' },
  { key: 'opcache.revalidate_freq', label: 'Revalidate Frequency', description: 'How often to check for updates (seconds)', placeholder: '2', section: 'opcache' },
  { key: 'opcache.save_comments', label: 'Save Comments', description: 'Save doc comments in opcode cache', type: 'toggle', section: 'opcache' },
  // Performance
  { key: 'realpath_cache_size', label: 'Realpath Cache Size', description: 'Size of realpath cache', placeholder: '4096k', section: 'performance' },
  { key: 'realpath_cache_ttl', label: 'Realpath Cache TTL', description: 'TTL for realpath cache entries (seconds)', placeholder: '120', section: 'performance' },
  { key: 'output_buffering', label: 'Output Buffering', description: 'Output buffer size (0=disabled)', placeholder: '4096', section: 'performance' },
  // Sessions
  { key: 'session.save_handler', label: 'Session Handler', description: 'How sessions are stored', placeholder: 'files', type: 'select', options: ['files', 'redis', 'memcached'], section: 'session' },
  { key: 'session.save_path', label: 'Session Save Path', description: 'Path or connection string for sessions', placeholder: '/var/lib/php/sessions', section: 'session' },
  { key: 'session.gc_maxlifetime', label: 'Session Lifetime', description: 'Session garbage collection lifetime (seconds)', placeholder: '1440', section: 'session' },
  { key: 'session.cookie_secure', label: 'Secure Cookies', description: 'Only send session cookie over HTTPS', type: 'toggle', section: 'session' },
  { key: 'session.cookie_httponly', label: 'HTTP Only Cookies', description: 'Make session cookie inaccessible to JavaScript', type: 'toggle', section: 'session' },
  { key: 'session.cookie_samesite', label: 'SameSite Policy', description: 'SameSite cookie attribute', type: 'select', options: ['', 'Lax', 'Strict', 'None'], section: 'session' },
  { key: 'redis.session.locking_enabled', label: 'Redis Session Locking', description: 'Enable session locking for Redis', type: 'toggle', section: 'session' },
  // Extensions
  { key: 'extension_redis', label: 'Redis Extension', description: 'Redis PHP extension status', type: 'status', section: 'extensions' },
  { key: 'extension_memcached', label: 'Memcached Extension', description: 'Memcached PHP extension status', type: 'status', section: 'extensions' },
  { key: 'extension_imagick', label: 'ImageMagick Extension', description: 'ImageMagick PHP extension status', type: 'status', section: 'extensions' },
  { key: 'extension_gd', label: 'GD Extension', description: 'GD image library status', type: 'status', section: 'extensions' },
  { key: 'extension_curl', label: 'cURL Extension', description: 'cURL extension status', type: 'status', section: 'extensions' },
  { key: 'extension_zip', label: 'Zip Extension', description: 'Zip extension status', type: 'status', section: 'extensions' },
  { key: 'extension_intl', label: 'Intl Extension', description: 'Internationalization extension status', type: 'status', section: 'extensions' },
  { key: 'extension_mbstring', label: 'Mbstring Extension', description: 'Multibyte string extension status', type: 'status', section: 'extensions' },
]

const phpSections = [
  { id: 'core', label: 'Core Settings', icon: 'settings' },
  { id: 'errors', label: 'Error Handling', icon: 'bug_report' },
  { id: 'security', label: 'Security', icon: 'security' },
  { id: 'opcache', label: 'OPCache', icon: 'speed' },
  { id: 'performance', label: 'Performance', icon: 'rocket_launch' },
  { id: 'session', label: 'Sessions', icon: 'cookie' },
  { id: 'extensions', label: 'Extensions', icon: 'extension' },
]

const getPhpSettingsBySection = (sectionId) => phpSettingDefinitions.filter(s => s.section === sectionId)
const phpHasChanges = computed(() => JSON.stringify(phpSettings.value) !== JSON.stringify(phpOriginalSettings.value))

const fetchPhpVersions = async () => {
  phpLoading.value = true
  try {
    const response = await api.get('/php/versions')
    if (response.data.success) {
      phpVersions.value = response.data.data.versions || []
      if (filteredPhpVersions.value.length > 0 && !selectedVersion.value) {
        selectedVersion.value = filteredPhpVersions.value[0].version
        // Set default php.ini file
        const handler = `lsphp${filteredPhpVersions.value[0].version.replace('.', '')}`
        phpSelectedConfigFile.value = `/usr/local/lsws/${handler}/etc/php/${filteredPhpVersions.value[0].version}/litespeed/php.ini`
      }
    }
  } catch (e) { toast.error('Failed to load PHP versions') }
  finally { phpLoading.value = false }
}

const fetchPhpSettings = async () => {
  if (!selectedVersion.value) return
  phpLoading.value = true
  try {
    const response = await api.get(`/php/${selectedVersion.value}/settings`)
    if (response.data.success) {
      phpSettings.value = response.data.data.settings || {}
      phpOriginalSettings.value = { ...phpSettings.value }
    }
  } catch (e) { toast.error('Failed to load PHP settings') }
  finally { phpLoading.value = false }
}

const savePhpSettings = async () => {
  phpSaving.value = true
  try {
    const response = await api.put(`/php/${selectedVersion.value}/settings`, { settings: phpSettings.value })
    if (response.data.success) {
      toast.success('PHP settings saved successfully')
      phpOriginalSettings.value = { ...phpSettings.value }
      phpEditMode.value = false
    } else toast.error(response.data.error || 'Failed to save settings')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to save settings') }
  finally { phpSaving.value = false }
}

// Save raw PHP config file
const savePhpRawConfig = async () => {
  if (!phpSelectedConfigFile.value || !phpRawConfig.value || !selectedVersion.value) return
  
  phpSaving.value = true
  try {
    const response = await api.put(`/php/${selectedVersion.value}/config/raw`, {
      content: phpRawConfig.value,
      file: phpSelectedConfigFile.value
    })
    
    if (response.data.success) {
      toast.success('PHP configuration saved successfully')
      
      // Restart PHP if it's a litespeed php.ini
      if (phpSelectedConfigFile.value.includes('/litespeed/')) {
        try {
          await api.post(`/php/${selectedVersion.value}/restart`)
          toast.success(`PHP ${selectedVersion.value} restarted`)
        } catch (e) {
          toast.warning('Config saved but PHP restart failed')
        }
      }
    } else {
      toast.error(response.data.error || 'Failed to save configuration')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save configuration')
  } finally {
    phpSaving.value = false
  }
}

const cancelPhpEdit = () => { phpSettings.value = { ...phpOriginalSettings.value }; phpEditMode.value = false }
const restartPhp = async () => {
  phpSaving.value = true
  try {
    const response = await api.post(`/php/${selectedVersion.value}/restart`)
    if (response.data.success) toast.success(`PHP ${selectedVersion.value} restarted successfully`)
    else toast.error(response.data.error || 'Failed to restart PHP')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to restart PHP') }
  finally { phpSaving.value = false }
}

watch(selectedVersion, () => { 
  if (selectedVersion.value) {
    fetchPhpSettings()
    // Update default config file to match selected version
    const handler = `lsphp${selectedVersion.value.replace('.', '')}`
    phpSelectedConfigFile.value = `/usr/local/lsws/${handler}/etc/php/${selectedVersion.value}/litespeed/php.ini`
  }
})

// ============================================
// MySQL State & Logic
// ============================================
const mysqlLoading = ref(true)
const mysqlSaving = ref(false)
const mysqlStatus = ref(null)
const mysqlSettings = ref({})
const mysqlOriginalSettings = ref({})
const mysqlEditMode = ref(false)
const mysqlRawMode = ref(false)
const mysqlRawConfig = ref('')
const mysqlRawLoading = ref(false)

// MySQL config files
const mysqlConfigFiles = [
  { path: '/etc/mysql/my.cnf', label: 'my.cnf', description: 'Main config file' },
  { path: '/etc/mysql/mysql.conf.d/mysqld.cnf', label: 'mysqld.cnf', description: 'Server configuration' },
  { path: '/etc/mysql/mysql.conf.d/mysql.cnf', label: 'mysql.cnf', description: 'Client configuration' },
  { path: '/etc/mysql/conf.d/mysql.cnf', label: 'conf.d/mysql.cnf', description: 'Client options' },
  { path: '/etc/mysql/conf.d/mysqldump.cnf', label: 'mysqldump.cnf', description: 'Dump options' },
  { path: '/etc/mysql/mariadb.conf.d/50-server.cnf', label: '50-server.cnf', description: 'MariaDB server config' },
  { path: '/etc/mysql/mariadb.conf.d/50-client.cnf', label: '50-client.cnf', description: 'MariaDB client config' },
]
const mysqlSelectedConfigFile = ref('/etc/mysql/mariadb.conf.d/50-server.cnf')

// Function to load MySQL raw config
const loadMysqlRawConfig = async (filePath, clearFirst = false) => {
  mysqlRawLoading.value = true
  if (clearFirst) {
    mysqlRawConfig.value = ''
  }
  try {
    const response = await api.get('/mysql/config/raw', { params: { file: filePath } })
    if (response.data.success) {
      mysqlRawConfig.value = response.data.data.content || ''
    } else {
      toast.error(response.data.error || 'Failed to load config file')
    }
  } catch (e) { 
    toast.error('Failed to load MySQL config: ' + (e.response?.data?.error || e.message))
  } finally {
    mysqlRawLoading.value = false
  }
}

// Watch for MySQL raw mode toggle or file change
watch([mysqlRawMode, mysqlSelectedConfigFile], async ([rawMode, filePath], [oldRawMode, oldFilePath]) => {
  if (rawMode) {
    // Load if entering raw mode OR if file changed while in raw mode
    if (!oldRawMode) {
      await loadMysqlRawConfig(filePath, true) // Clear on initial load
    } else if (filePath !== oldFilePath) {
      await loadMysqlRawConfig(filePath) // Don't clear when switching files
    }
  }
})
const mysqlVariables = ref([])
const mysqlSearchQuery = ref('')

const mysqlSettingDefinitions = [
  // Connection
  { key: 'max_connections', label: 'Max Connections', description: 'Maximum simultaneous connections', placeholder: '151', section: 'connection' },
  { key: 'max_allowed_packet', label: 'Max Allowed Packet', description: 'Maximum size of one packet', placeholder: '64M', section: 'connection' },
  { key: 'wait_timeout', label: 'Wait Timeout', description: 'Seconds to wait for activity on connection', placeholder: '28800', section: 'connection' },
  { key: 'interactive_timeout', label: 'Interactive Timeout', description: 'Seconds for interactive connections', placeholder: '28800', section: 'connection' },
  { key: 'connect_timeout', label: 'Connect Timeout', description: 'Seconds to wait for connection packet', placeholder: '10', section: 'connection' },
  { key: 'skip_name_resolve', label: 'Skip Name Resolve', description: 'Disable DNS hostname lookups (faster)', type: 'toggle', section: 'connection' },
  // Character Set
  { key: 'character_set_server', label: 'Character Set', description: 'Default server character set', placeholder: 'utf8mb4', type: 'select', options: ['utf8mb4', 'utf8', 'latin1'], section: 'charset' },
  { key: 'collation_server', label: 'Collation', description: 'Default server collation', placeholder: 'utf8mb4_unicode_ci', section: 'charset' },
  { key: 'init_connect', label: 'Init Connect', description: 'SQL to execute for each new connection', placeholder: 'SET NAMES utf8mb4', section: 'charset' },
  // SQL Mode
  { key: 'sql_mode', label: 'SQL Mode', description: 'Server SQL mode settings', placeholder: 'STRICT_TRANS_TABLES,NO_ZERO_DATE', section: 'sqlmode' },
  // InnoDB
  { key: 'innodb_buffer_pool_size', label: 'Buffer Pool Size', description: 'Memory for caching data and indexes (50-80% of RAM)', placeholder: '128M', section: 'innodb' },
  { key: 'innodb_buffer_pool_instances', label: 'Buffer Pool Instances', description: 'Number of buffer pool regions', placeholder: '1', section: 'innodb' },
  { key: 'innodb_log_file_size', label: 'Log File Size', description: 'Size of each InnoDB redo log file', placeholder: '48M', section: 'innodb' },
  { key: 'innodb_log_buffer_size', label: 'Log Buffer Size', description: 'Size of the buffer for transaction logs', placeholder: '16M', section: 'innodb' },
  { key: 'innodb_flush_log_at_trx_commit', label: 'Flush Log at Commit', description: 'When to flush log (1=safe, 2=fast)', placeholder: '1', type: 'select', options: ['0', '1', '2'], section: 'innodb' },
  { key: 'innodb_file_per_table', label: 'File Per Table', description: 'Store each table in separate file', type: 'toggle', section: 'innodb' },
  { key: 'innodb_flush_method', label: 'Flush Method', description: 'Method to flush data to disk', placeholder: 'O_DIRECT', type: 'select', options: ['fsync', 'O_DSYNC', 'O_DIRECT', 'O_DIRECT_NO_FSYNC'], section: 'innodb' },
  { key: 'innodb_io_capacity', label: 'IO Capacity', description: 'I/O operations per second (SSD: 2000+)', placeholder: '200', section: 'innodb' },
  { key: 'innodb_io_capacity_max', label: 'IO Capacity Max', description: 'Max I/O operations per second', placeholder: '400', section: 'innodb' },
  { key: 'innodb_read_io_threads', label: 'Read IO Threads', description: 'Background threads for read I/O', placeholder: '4', section: 'innodb' },
  { key: 'innodb_write_io_threads', label: 'Write IO Threads', description: 'Background threads for write I/O', placeholder: '4', section: 'innodb' },
  // Performance
  { key: 'tmp_table_size', label: 'Temp Table Size', description: 'Max size of internal in-memory tables', placeholder: '16M', section: 'performance' },
  { key: 'max_heap_table_size', label: 'Max Heap Table Size', description: 'Max size of MEMORY tables', placeholder: '16M', section: 'performance' },
  { key: 'table_open_cache', label: 'Table Open Cache', description: 'Number of open tables for all threads', placeholder: '4000', section: 'performance' },
  { key: 'table_definition_cache', label: 'Table Definition Cache', description: 'Number of table definitions to cache', placeholder: '2000', section: 'performance' },
  { key: 'thread_cache_size', label: 'Thread Cache Size', description: 'Number of threads to cache for reuse', placeholder: '8', section: 'performance' },
  { key: 'sort_buffer_size', label: 'Sort Buffer Size', description: 'Buffer for sorting operations', placeholder: '256K', section: 'performance' },
  { key: 'join_buffer_size', label: 'Join Buffer Size', description: 'Buffer for join operations', placeholder: '256K', section: 'performance' },
  { key: 'read_buffer_size', label: 'Read Buffer Size', description: 'Buffer for sequential table scans', placeholder: '128K', section: 'performance' },
  // Logging
  { key: 'slow_query_log', label: 'Slow Query Log', description: 'Enable slow query logging', type: 'toggle', section: 'logging' },
  { key: 'long_query_time', label: 'Long Query Time', description: 'Queries longer than this are logged (seconds)', placeholder: '2', section: 'logging' },
  { key: 'log_queries_not_using_indexes', label: 'Log No Index Queries', description: 'Log queries not using indexes', type: 'toggle', section: 'logging' },
  { key: 'general_log', label: 'General Log', description: 'Log all queries (performance impact)', type: 'toggle', section: 'logging' },
  { key: 'log_error_verbosity', label: 'Error Log Verbosity', description: 'Error log detail level (1-3)', placeholder: '2', type: 'select', options: ['1', '2', '3'], section: 'logging' },
  // Replication
  { key: 'binlog_format', label: 'Binlog Format', description: 'Binary log format', placeholder: 'ROW', type: 'select', options: ['ROW', 'STATEMENT', 'MIXED'], section: 'replication' },
  { key: 'expire_logs_days', label: 'Expire Logs Days', description: 'Days to keep binary logs', placeholder: '10', section: 'replication' },
  { key: 'max_binlog_size', label: 'Max Binlog Size', description: 'Maximum binary log file size', placeholder: '100M', section: 'replication' },
  { key: 'sync_binlog', label: 'Sync Binlog', description: 'Sync binlog to disk every N commits (1=safest)', placeholder: '1', section: 'replication' },
]

const mysqlSections = [
  { id: 'connection', label: 'Connection Settings', icon: 'cable' },
  { id: 'charset', label: 'Character Set', icon: 'translate' },
  { id: 'sqlmode', label: 'SQL Mode', icon: 'code' },
  { id: 'innodb', label: 'InnoDB Storage', icon: 'storage' },
  { id: 'performance', label: 'Performance', icon: 'speed' },
  { id: 'logging', label: 'Logging', icon: 'description' },
  { id: 'replication', label: 'Replication', icon: 'sync' },
]

const getMysqlSettingsBySection = (sectionId) => mysqlSettingDefinitions.filter(s => s.section === sectionId)
const mysqlHasChanges = computed(() => JSON.stringify(mysqlSettings.value) !== JSON.stringify(mysqlOriginalSettings.value))
const filteredMysqlVariables = computed(() => {
  if (!mysqlSearchQuery.value) return mysqlVariables.value.slice(0, 50)
  const q = mysqlSearchQuery.value.toLowerCase()
  return mysqlVariables.value.filter(v => v.name.toLowerCase().includes(q) || v.value.toString().toLowerCase().includes(q)).slice(0, 50)
})

const fetchMysqlStatus = async () => {
  try {
    const response = await api.get('/mysql/status')
    if (response.data.success) mysqlStatus.value = response.data.data
  } catch (e) { console.error('Failed to fetch MySQL status', e) }
}

const fetchMysqlSettings = async () => {
  mysqlLoading.value = true
  try {
    const response = await api.get('/mysql/settings')
    if (response.data.success) {
      mysqlSettings.value = response.data.data.settings || {}
      mysqlOriginalSettings.value = { ...mysqlSettings.value }
      mysqlVariables.value = response.data.data.variables || []
    }
  } catch (e) { toast.error('Failed to load MySQL settings') }
  finally { mysqlLoading.value = false }
}

const saveMysqlSettings = async () => {
  mysqlSaving.value = true
  try {
    const response = await api.put('/mysql/settings', { settings: mysqlSettings.value })
    if (response.data.success) {
      toast.success('MySQL settings saved successfully')
      mysqlOriginalSettings.value = { ...mysqlSettings.value }
      mysqlEditMode.value = false
    } else toast.error(response.data.error || 'Failed to save settings')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to save settings') }
  finally { mysqlSaving.value = false }
}

// Save raw config file for MySQL
const saveMysqlRawConfig = async () => {
  mysqlSaving.value = true
  try {
    // Base64 encode content to bypass WAF/ModSecurity
    const encodedContent = btoa(unescape(encodeURIComponent(mysqlRawConfig.value)))
    const response = await api.put('/mysql/config/raw', { 
      file: mysqlSelectedConfigFile.value,
      content_b64: encodedContent 
    })
    if (response.data.success) {
      toast.success('MySQL configuration saved successfully')
      await restartMysql()
    } else toast.error(response.data.error || 'Failed to save config')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to save config') }
  finally { mysqlSaving.value = false }
}

const cancelMysqlEdit = () => { mysqlSettings.value = { ...mysqlOriginalSettings.value }; mysqlEditMode.value = false }
const restartMysql = async () => {
  mysqlSaving.value = true
  try {
    const response = await api.post('/mysql/restart')
    if (response.data.success) { toast.success('MySQL restarted successfully'); await fetchMysqlStatus() }
    else toast.error(response.data.error || 'Failed to restart MySQL')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to restart MySQL') }
  finally { mysqlSaving.value = false }
}

// ============================================
// Postfix State & Logic
// ============================================
const postfixLoading = ref(true)
const postfixSaving = ref(false)
const postfixRawLoading = ref(false)
const postfixStatus = ref(null)
const postfixSettings = ref({})
const postfixOriginalSettings = ref({})
const postfixEditMode = ref(false)
const postfixRawMode = ref(false)
const postfixRawConfig = ref('')
const postfixSelectedConfigFile = ref('/etc/postfix/main.cf')
const postfixConfigPath = computed(() => postfixSelectedConfigFile.value)

// Postfix config files (only essential .cf files that always exist)
const postfixConfigFiles = [
  { path: '/etc/postfix/main.cf', label: 'main.cf', description: 'Main configuration' },
  { path: '/etc/postfix/master.cf', label: 'master.cf', description: 'Process configuration' },
  { path: '/etc/postfix/mysql-virtual_domains.cf', label: 'mysql-virtual_domains.cf', description: 'Virtual domains lookup' },
  { path: '/etc/postfix/mysql-virtual_email2email.cf', label: 'mysql-virtual_email2email.cf', description: 'Email validation lookup' },
  { path: '/etc/postfix/mysql-virtual_mailboxes.cf', label: 'mysql-virtual_mailboxes.cf', description: 'Mailbox mapping' },
  { path: '/etc/postfix/mysql-virtual_forwardings.cf', label: 'mysql-virtual_forwardings.cf', description: 'Email forwarding rules' },
]

// Function to load Postfix raw config
const loadPostfixRawConfig = async (filePath, clearFirst = false) => {
  postfixRawLoading.value = true
  // Only clear content on initial load, not when switching files in zen mode
  if (clearFirst) {
    postfixRawConfig.value = ''
  }
  try {
    const response = await api.get('/postfix/config/raw', { params: { file: filePath } })
    if (response.data.success) {
      postfixRawConfig.value = response.data.data.content || ''
    } else {
      toast.error(response.data.error || 'Failed to load config file')
    }
  } catch (e) { 
    toast.error('Failed to load Postfix config: ' + (e.response?.data?.error || e.message))
  } finally {
    postfixRawLoading.value = false
  }
}

// Watch for Postfix raw mode toggle
watch(postfixRawMode, async (newRawMode) => {
  if (newRawMode) {
    await loadPostfixRawConfig(postfixSelectedConfigFile.value, true) // Clear on initial load
  }
})

// Watch for config file change while in raw mode
watch(postfixSelectedConfigFile, async (newFile) => {
  if (postfixRawMode.value) {
    await loadPostfixRawConfig(newFile)
  }
})
const postfixQueue = ref([])

const postfixSettingDefinitions = [
  // General Settings
  { key: 'myhostname', label: 'Hostname', description: 'Mail server hostname (FQDN)', placeholder: 'mail.example.com', section: 'general' },
  { key: 'mydomain', label: 'Domain', description: 'Mail domain', placeholder: 'example.com', section: 'general' },
  { key: 'myorigin', label: 'Origin', description: 'Domain appended to locally-posted mail', placeholder: '$mydomain', section: 'general' },
  { key: 'inet_interfaces', label: 'Listen Interfaces', description: 'Network interfaces to listen on', placeholder: 'all', section: 'general' },
  { key: 'inet_protocols', label: 'IP Protocols', description: 'IP protocols to use', placeholder: 'all', type: 'select', options: ['all', 'ipv4', 'ipv6'], section: 'general' },
  { key: 'mydestination', label: 'Destinations', description: 'Domains this server accepts mail for', placeholder: '$myhostname, localhost', section: 'general' },
  { key: 'mynetworks', label: 'My Networks', description: 'Trusted networks for relaying', placeholder: '127.0.0.0/8', section: 'general' },
  { key: 'relayhost', label: 'Relay Host', description: 'Host to relay outbound mail through (empty for direct)', placeholder: '', section: 'general' },
  { key: 'smtpd_banner', label: 'SMTP Banner', description: 'Greeting banner for SMTP connections', placeholder: '$myhostname ESMTP', section: 'general' },
  // Virtual Domains (for multi-domain mail servers)
  { key: 'virtual_mailbox_domains', label: 'Virtual Domains', description: 'Domains for virtual mailboxes', placeholder: 'mysql:/etc/postfix/mysql-virtual-domains.cf', section: 'virtual' },
  { key: 'virtual_mailbox_maps', label: 'Mailbox Maps', description: 'Mapping of email addresses to mailboxes', placeholder: 'mysql:/etc/postfix/mysql-virtual-mailbox.cf', section: 'virtual' },
  { key: 'virtual_alias_maps', label: 'Alias Maps', description: 'Email alias mappings', placeholder: 'mysql:/etc/postfix/mysql-virtual-alias.cf', section: 'virtual' },
  { key: 'virtual_transport', label: 'Virtual Transport', description: 'Delivery agent for virtual mailboxes', placeholder: 'lmtp:unix:private/dovecot-lmtp', section: 'virtual' },
  { key: 'virtual_mailbox_base', label: 'Mailbox Base', description: 'Base directory for virtual mailboxes', placeholder: '/var/mail/vhosts', section: 'virtual' },
  { key: 'virtual_uid_maps', label: 'UID Maps', description: 'UID for virtual mailbox owner', placeholder: 'static:5000', section: 'virtual' },
  { key: 'virtual_gid_maps', label: 'GID Maps', description: 'GID for virtual mailbox owner', placeholder: 'static:5000', section: 'virtual' },
  // TLS/SSL
  { key: 'smtpd_use_tls', label: 'Enable TLS', description: 'Enable TLS for incoming SMTP', type: 'toggle', section: 'tls' },
  { key: 'smtpd_tls_security_level', label: 'TLS Security Level', description: 'TLS enforcement for incoming', placeholder: 'may', type: 'select', options: ['none', 'may', 'encrypt'], section: 'tls' },
  { key: 'smtpd_tls_cert_file', label: 'TLS Certificate', description: 'Path to SSL certificate', placeholder: '/etc/letsencrypt/live/mail.example.com/fullchain.pem', section: 'tls' },
  { key: 'smtpd_tls_key_file', label: 'TLS Private Key', description: 'Path to SSL private key', placeholder: '/etc/letsencrypt/live/mail.example.com/privkey.pem', section: 'tls' },
  { key: 'smtpd_tls_protocols', label: 'TLS Protocols', description: 'Allowed TLS protocols', placeholder: '!SSLv2,!SSLv3,!TLSv1,!TLSv1.1', section: 'tls' },
  { key: 'smtp_use_tls', label: 'Outbound TLS', description: 'Use TLS for outgoing mail', type: 'toggle', section: 'tls' },
  { key: 'smtp_tls_security_level', label: 'Outbound TLS Level', description: 'TLS enforcement for outgoing', placeholder: 'may', type: 'select', options: ['none', 'may', 'encrypt', 'dane'], section: 'tls' },
  // Authentication
  { key: 'smtpd_sasl_auth_enable', label: 'SASL Auth', description: 'Enable SMTP authentication', type: 'toggle', section: 'auth' },
  { key: 'smtpd_sasl_type', label: 'SASL Type', description: 'SASL authentication type', placeholder: 'dovecot', section: 'auth' },
  { key: 'smtpd_sasl_path', label: 'SASL Path', description: 'Path to SASL auth socket', placeholder: 'private/auth', section: 'auth' },
  { key: 'smtpd_sasl_security_options', label: 'SASL Security', description: 'SASL security options', placeholder: 'noanonymous', section: 'auth' },
  { key: 'smtpd_sasl_local_domain', label: 'SASL Domain', description: 'Local domain for SASL auth', placeholder: '$myhostname', section: 'auth' },
  // Spam Prevention / Restrictions
  { key: 'smtpd_helo_required', label: 'Require HELO', description: 'Require clients to send HELO/EHLO', type: 'toggle', section: 'restrictions' },
  { key: 'strict_rfc821_envelopes', label: 'Strict RFC821', description: 'Enforce strict RFC821 envelope addresses', type: 'toggle', section: 'restrictions' },
  { key: 'disable_vrfy_command', label: 'Disable VRFY', description: 'Disable address verification command', type: 'toggle', section: 'restrictions' },
  { key: 'smtpd_delay_reject', label: 'Delay Reject', description: 'Delay rejection until RCPT TO', type: 'toggle', section: 'restrictions' },
  { key: 'smtpd_helo_restrictions', label: 'HELO Restrictions', description: 'Restrictions on HELO command', placeholder: 'permit_mynetworks, reject_invalid_helo_hostname', section: 'restrictions' },
  { key: 'smtpd_sender_restrictions', label: 'Sender Restrictions', description: 'Restrictions on sender address', placeholder: 'permit_mynetworks, reject_unknown_sender_domain', section: 'restrictions' },
  { key: 'smtpd_recipient_restrictions', label: 'Recipient Restrictions', description: 'Restrictions on recipient address', placeholder: 'permit_mynetworks, permit_sasl_authenticated, reject_unauth_destination', section: 'restrictions' },
  { key: 'smtpd_relay_restrictions', label: 'Relay Restrictions', description: 'Who can relay through this server', placeholder: 'permit_mynetworks, permit_sasl_authenticated, defer_unauth_destination', section: 'restrictions' },
  // DKIM / Mail Filters
  { key: 'milter_default_action', label: 'Milter Default Action', description: 'Action when milter unavailable', placeholder: 'accept', type: 'select', options: ['accept', 'reject', 'tempfail'], section: 'dkim' },
  { key: 'milter_protocol', label: 'Milter Protocol', description: 'Milter protocol version', placeholder: '6', section: 'dkim' },
  { key: 'smtpd_milters', label: 'SMTP Milters', description: 'Mail filters for incoming mail (DKIM)', placeholder: 'inet:localhost:8891', section: 'dkim' },
  { key: 'non_smtpd_milters', label: 'Non-SMTP Milters', description: 'Mail filters for locally-generated mail', placeholder: 'inet:localhost:8891', section: 'dkim' },
  // Limits
  { key: 'message_size_limit', label: 'Message Size Limit', description: 'Maximum email size in bytes', placeholder: '52428800', section: 'limits' },
  { key: 'mailbox_size_limit', label: 'Mailbox Size Limit', description: 'Maximum mailbox size (0=unlimited)', placeholder: '0', section: 'limits' },
  { key: 'smtpd_recipient_limit', label: 'Recipient Limit', description: 'Max recipients per message', placeholder: '100', section: 'limits' },
  { key: 'smtpd_client_connection_count_limit', label: 'Connection Count Limit', description: 'Max connections from single client', placeholder: '10', section: 'limits' },
  { key: 'smtpd_client_connection_rate_limit', label: 'Connection Rate Limit', description: 'Max connections per minute from client', placeholder: '0', section: 'limits' },
  { key: 'smtpd_client_message_rate_limit', label: 'Message Rate Limit', description: 'Max messages per minute from client', placeholder: '0', section: 'limits' },
]

const postfixSections = [
  { id: 'general', label: 'General', icon: 'settings' },
  { id: 'virtual', label: 'Virtual Domains', icon: 'domain' },
  { id: 'tls', label: 'TLS/SSL', icon: 'lock' },
  { id: 'auth', label: 'Authentication', icon: 'key' },
  { id: 'restrictions', label: 'Spam Prevention', icon: 'shield' },
  { id: 'dkim', label: 'DKIM / Mail Filters', icon: 'verified' },
  { id: 'limits', label: 'Limits', icon: 'speed' },
]

const getPostfixSettingsBySection = (sectionId) => postfixSettingDefinitions.filter(s => s.section === sectionId)

const postfixHasChanges = computed(() => JSON.stringify(postfixSettings.value) !== JSON.stringify(postfixOriginalSettings.value))

const fetchPostfixStatus = async () => {
  try {
    const response = await api.get('/postfix/status')
    if (response.data.success) postfixStatus.value = response.data.data
  } catch (e) { console.error('Failed to fetch Postfix status', e) }
}

const fetchPostfixSettings = async () => {
  postfixLoading.value = true
  try {
    const response = await api.get('/postfix/settings')
    if (response.data.success) {
      postfixSettings.value = response.data.data.settings || {}
      postfixOriginalSettings.value = { ...postfixSettings.value }
      postfixQueue.value = response.data.data.queue || []
    }
  } catch (e) { toast.error('Failed to load Postfix settings') }
  finally { postfixLoading.value = false }
}

const savePostfixSettings = async () => {
  postfixSaving.value = true
  try {
    const response = await api.put('/postfix/settings', { settings: postfixSettings.value })
    if (response.data.success) {
      toast.success('Postfix settings saved successfully')
      postfixOriginalSettings.value = { ...postfixSettings.value }
      postfixEditMode.value = false
    } else toast.error(response.data.error || 'Failed to save settings')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to save settings') }
  finally { postfixSaving.value = false }
}

const cancelPostfixEdit = () => { postfixSettings.value = { ...postfixOriginalSettings.value }; postfixEditMode.value = false }

// Save raw config file for Postfix
const savePostfixRawConfig = async () => {
  postfixSaving.value = true
  try {
    // Base64 encode content to bypass WAF/ModSecurity
    const encodedContent = btoa(unescape(encodeURIComponent(postfixRawConfig.value)))
    const response = await api.put('/postfix/config/raw', { 
      file: postfixSelectedConfigFile.value,
      content_b64: encodedContent 
    })
    if (response.data.success) {
      toast.success('Postfix configuration saved successfully')
      // Restart postfix and refresh settings so guide shows updated values
      await restartPostfix()
      await fetchPostfixSettings() // Refresh settings to persist guide state
    } else toast.error(response.data.error || 'Failed to save config')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to save config') }
  finally { postfixSaving.value = false }
}

const restartPostfix = async () => {
  postfixSaving.value = true
  try {
    const response = await api.post('/postfix/restart')
    if (response.data.success) { toast.success('Postfix restarted successfully'); await fetchPostfixStatus() }
    else toast.error(response.data.error || 'Failed to restart Postfix')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to restart Postfix') }
  finally { postfixSaving.value = false }
}

const flushQueue = async () => {
  postfixSaving.value = true
  try {
    const response = await api.post('/postfix/flush')
    if (response.data.success) { toast.success('Mail queue flushed'); await fetchPostfixSettings() }
    else toast.error(response.data.error || 'Failed to flush queue')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to flush queue') }
  finally { postfixSaving.value = false }
}

// ============================================
// Dovecot State & Logic
// ============================================
const dovecotLoading = ref(true)
const dovecotSaving = ref(false)
const dovecotRawLoading = ref(false)
const dovecotStatus = ref(null)
const dovecotSettings = ref({})
const dovecotOriginalSettings = ref({})
const dovecotEditMode = ref(false)
const dovecotRawMode = ref(false)
const dovecotRawConfig = ref('')
const dovecotSelectedConfigFile = ref('/etc/dovecot/dovecot.conf')
const dovecotConfigPath = computed(() => dovecotSelectedConfigFile.value)

// Dovecot config files
const dovecotConfigFiles = [
  { path: '/etc/dovecot/dovecot.conf', label: 'dovecot.conf', description: 'Main configuration' },
  { path: '/etc/dovecot/conf.d/10-auth.conf', label: '10-auth.conf', description: 'Authentication settings' },
  { path: '/etc/dovecot/conf.d/10-logging.conf', label: '10-logging.conf', description: 'Logging configuration' },
  { path: '/etc/dovecot/conf.d/10-mail.conf', label: '10-mail.conf', description: 'Mail storage settings' },
  { path: '/etc/dovecot/conf.d/10-master.conf', label: '10-master.conf', description: 'Services configuration' },
  { path: '/etc/dovecot/conf.d/10-ssl.conf', label: '10-ssl.conf', description: 'SSL/TLS settings' },
  { path: '/etc/dovecot/conf.d/15-lda.conf', label: '15-lda.conf', description: 'Local delivery agent' },
  { path: '/etc/dovecot/conf.d/15-mailboxes.conf', label: '15-mailboxes.conf', description: 'Mailbox structure' },
  { path: '/etc/dovecot/conf.d/20-imap.conf', label: '20-imap.conf', description: 'IMAP protocol settings' },
  { path: '/etc/dovecot/conf.d/20-pop3.conf', label: '20-pop3.conf', description: 'POP3 protocol settings' },
  { path: '/etc/dovecot/conf.d/90-quota.conf', label: '90-quota.conf', description: 'Quota settings' },
]

// Function to load Dovecot raw config
const loadDovecotRawConfig = async (filePath, clearFirst = false) => {
  dovecotRawLoading.value = true
  if (clearFirst) {
    dovecotRawConfig.value = ''
  }
  try {
    const response = await api.get('/dovecot/config/raw', { params: { file: filePath } })
    if (response.data.success) {
      dovecotRawConfig.value = response.data.data.content || ''
    } else {
      toast.error(response.data.error || 'Failed to load config file')
    }
  } catch (e) { 
    toast.error('Failed to load Dovecot config: ' + (e.response?.data?.error || e.message))
  } finally {
    dovecotRawLoading.value = false
  }
}

// Watch for Dovecot raw mode toggle
watch(dovecotRawMode, async (newRawMode) => {
  if (newRawMode) {
    await loadDovecotRawConfig(dovecotSelectedConfigFile.value, true) // Clear on initial load
  }
})

// Watch for config file change while in raw mode
watch(dovecotSelectedConfigFile, async (newFile) => {
  if (dovecotRawMode.value) {
    await loadDovecotRawConfig(newFile)
  }
})
const dovecotConnections = ref([])

const dovecotSettingDefinitions = [
  // General / Protocols
  { key: 'protocols', label: 'Protocols', description: 'Enabled protocols', placeholder: 'imap pop3 lmtp', section: 'general' },
  { key: 'listen', label: 'Listen Addresses', description: 'IP addresses to listen on', placeholder: '*, ::', section: 'general' },
  { key: 'mail_location', label: 'Mail Location', description: 'Path to mailbox storage', placeholder: 'maildir:/var/mail/vhosts/%d/%n/Maildir', section: 'general' },
  { key: 'mail_home', label: 'Mail Home', description: 'Home directory template for users', placeholder: '/var/mail/vhosts/%d/%n', section: 'general' },
  { key: 'mail_uid', label: 'Mail UID', description: 'User ID for mail operations', placeholder: 'vmail', section: 'general' },
  { key: 'mail_gid', label: 'Mail GID', description: 'Group ID for mail operations', placeholder: 'vmail', section: 'general' },
  { key: 'mail_privileged_group', label: 'Privileged Group', description: 'Group for accessing mails', placeholder: 'mail', section: 'general' },
  { key: 'first_valid_uid', label: 'First Valid UID', description: 'Minimum user ID that can login', placeholder: '1000', section: 'general' },
  { key: 'last_valid_uid', label: 'Last Valid UID', description: 'Maximum user ID (0=no limit)', placeholder: '0', section: 'general' },
  // SSL/TLS
  { key: 'ssl', label: 'SSL Mode', description: 'SSL/TLS mode for connections', type: 'select', options: ['no', 'yes', 'required'], section: 'ssl' },
  { key: 'ssl_cert', label: 'SSL Certificate', description: 'Path to SSL certificate', placeholder: '</etc/letsencrypt/live/mail.example.com/fullchain.pem', section: 'ssl' },
  { key: 'ssl_key', label: 'SSL Private Key', description: 'Path to SSL private key', placeholder: '</etc/letsencrypt/live/mail.example.com/privkey.pem', section: 'ssl' },
  { key: 'ssl_min_protocol', label: 'Min SSL Protocol', description: 'Minimum SSL/TLS version', type: 'select', options: ['TLSv1', 'TLSv1.1', 'TLSv1.2', 'TLSv1.3'], section: 'ssl' },
  { key: 'ssl_prefer_server_ciphers', label: 'Prefer Server Ciphers', description: 'Use server cipher preferences', type: 'toggle', section: 'ssl' },
  { key: 'ssl_cipher_list', label: 'Cipher List', description: 'Allowed SSL ciphers', placeholder: 'HIGH:!aNULL:!MD5', section: 'ssl' },
  // Authentication
  { key: 'auth_mechanisms', label: 'Auth Mechanisms', description: 'Allowed authentication methods', placeholder: 'plain login', section: 'auth' },
  { key: 'disable_plaintext_auth', label: 'Disable Plaintext Auth', description: 'Disallow plain auth without SSL', type: 'toggle', section: 'auth' },
  { key: 'auth_username_format', label: 'Username Format', description: 'Format for usernames', placeholder: '%Lu', section: 'auth' },
  { key: 'auth_verbose', label: 'Verbose Auth Logging', description: 'Enable detailed auth logging', type: 'toggle', section: 'auth' },
  { key: 'auth_verbose_passwords', label: 'Log Passwords', description: 'Log password attempts (debugging)', type: 'select', options: ['no', 'plain', 'sha1'], section: 'auth' },
  { key: 'auth_debug', label: 'Auth Debug', description: 'Enable auth debugging', type: 'toggle', section: 'auth' },
  // LMTP (Local Mail Transfer)
  { key: 'postmaster_address', label: 'Postmaster Address', description: 'Address for postmaster mail', placeholder: 'postmaster@example.com', section: 'lmtp' },
  { key: 'lmtp_save_to_detail_mailbox', label: 'Save to Detail Mailbox', description: 'Save to folder based on +detail', type: 'toggle', section: 'lmtp' },
  { key: 'recipient_delimiter', label: 'Recipient Delimiter', description: 'Delimiter for address extensions', placeholder: '+', section: 'lmtp' },
  // Plugins
  { key: 'mail_plugins', label: 'Global Mail Plugins', description: 'Plugins loaded for all protocols', placeholder: 'quota zlib', section: 'plugins' },
  { key: 'protocol_imap_mail_plugins', label: 'IMAP Plugins', description: 'Additional plugins for IMAP', placeholder: 'imap_quota imap_sieve imap_zlib', section: 'plugins' },
  { key: 'protocol_pop3_mail_plugins', label: 'POP3 Plugins', description: 'Additional plugins for POP3', placeholder: 'zlib', section: 'plugins' },
  { key: 'protocol_lmtp_mail_plugins', label: 'LMTP Plugins', description: 'Additional plugins for LMTP', placeholder: 'sieve', section: 'plugins' },
  // Quota (plugin_ prefix from API)
  { key: 'plugin_quota', label: 'Quota Backend', description: 'Quota storage backend', placeholder: 'maildir:User quota', section: 'quota' },
  { key: 'plugin_quota_rule', label: 'Default Quota', description: 'Default quota limit', placeholder: '*:storage=1G', section: 'quota' },
  { key: 'plugin_quota_rule2', label: 'Trash Quota', description: 'Quota rule for Trash folder', placeholder: 'Trash:storage=+100M', section: 'quota' },
  { key: 'plugin_quota_warning', label: 'Quota Warning', description: 'Warning when quota exceeded', placeholder: 'storage=95%% quota-warning 95 %u', section: 'quota' },
  { key: 'plugin_quota_grace', label: 'Quota Grace', description: 'Allow slightly over quota', placeholder: '10%%', section: 'quota' },
  // Sieve (Mail Filtering - plugin_ prefix from API)
  { key: 'plugin_sieve', label: 'User Sieve Script', description: 'Path to user sieve script', placeholder: '~/.dovecot.sieve', section: 'sieve' },
  { key: 'plugin_sieve_global_dir', label: 'Global Sieve Dir', description: 'Directory for global sieve scripts', placeholder: '/etc/dovecot/sieve/', section: 'sieve' },
  { key: 'plugin_sieve_before', label: 'Sieve Before', description: 'Scripts to run before user sieve', placeholder: '/etc/dovecot/sieve/before.d/', section: 'sieve' },
  { key: 'plugin_sieve_after', label: 'Sieve After', description: 'Scripts to run after user sieve', placeholder: '/etc/dovecot/sieve/after.d/', section: 'sieve' },
  // Compression plugin
  { key: 'plugin_zlib_save', label: 'Compression Format', description: 'Compression format for saved mail', placeholder: 'gz', section: 'plugins' },
  { key: 'plugin_zlib_save_level', label: 'Compression Level', description: 'Compression level (1-9)', placeholder: '6', section: 'plugins' },
  // Limits
  { key: 'mail_max_userip_connections', label: 'Max Connections per IP', description: 'Maximum connections per user from single IP', placeholder: '10', section: 'limits' },
  { key: 'imap_max_line_length', label: 'IMAP Max Line Length', description: 'Maximum IMAP command line length', placeholder: '64k', section: 'limits' },
  { key: 'imap_idle_notify_interval', label: 'IMAP Idle Notify', description: 'Seconds between IDLE notifications', placeholder: '2 mins', section: 'limits' },
  { key: 'pop3_uidl_format', label: 'POP3 UIDL Format', description: 'Format for POP3 unique IDs', placeholder: '%08Xu%08Xv', section: 'limits' },
  // Logging
  { key: 'log_path', label: 'Log Path', description: 'Path to log file', placeholder: '/var/log/dovecot.log', section: 'logging' },
  { key: 'info_log_path', label: 'Info Log Path', description: 'Path for info level logs', placeholder: '/var/log/dovecot-info.log', section: 'logging' },
  { key: 'debug_log_path', label: 'Debug Log Path', description: 'Path for debug logs', placeholder: '/var/log/dovecot-debug.log', section: 'logging' },
  { key: 'mail_debug', label: 'Mail Debug', description: 'Enable mail debugging', type: 'toggle', section: 'logging' },
  { key: 'verbose_ssl', label: 'Verbose SSL', description: 'Enable SSL debugging', type: 'toggle', section: 'logging' },
]

const dovecotSections = [
  { id: 'general', label: 'General', icon: 'settings' },
  { id: 'ssl', label: 'SSL/TLS', icon: 'lock' },
  { id: 'auth', label: 'Authentication', icon: 'key' },
  { id: 'lmtp', label: 'LMTP', icon: 'move_to_inbox' },
  { id: 'plugins', label: 'Plugins', icon: 'extension' },
  { id: 'quota', label: 'Quota', icon: 'pie_chart' },
  { id: 'sieve', label: 'Sieve Filtering', icon: 'filter_alt' },
  { id: 'limits', label: 'Limits', icon: 'speed' },
  { id: 'logging', label: 'Logging', icon: 'description' },
]

const getDovecotSettingsBySection = (sectionId) => dovecotSettingDefinitions.filter(s => s.section === sectionId)

const dovecotHasChanges = computed(() => JSON.stringify(dovecotSettings.value) !== JSON.stringify(dovecotOriginalSettings.value))

const fetchDovecotStatus = async () => {
  try {
    const response = await api.get('/dovecot/status')
    if (response.data.success) dovecotStatus.value = response.data.data
  } catch (e) { console.error('Failed to fetch Dovecot status', e) }
}

const fetchDovecotSettings = async () => {
  dovecotLoading.value = true
  try {
    const response = await api.get('/dovecot/settings')
    if (response.data.success) {
      dovecotSettings.value = response.data.data.settings || {}
      dovecotOriginalSettings.value = { ...dovecotSettings.value }
      dovecotConnections.value = response.data.data.connections || []
    }
  } catch (e) { toast.error('Failed to load Dovecot settings') }
  finally { dovecotLoading.value = false }
}

const saveDovecotSettings = async () => {
  dovecotSaving.value = true
  try {
    const response = await api.put('/dovecot/settings', { settings: dovecotSettings.value })
    if (response.data.success) {
      toast.success('Dovecot settings saved successfully')
      dovecotOriginalSettings.value = { ...dovecotSettings.value }
      dovecotEditMode.value = false
    } else toast.error(response.data.error || 'Failed to save settings')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to save settings') }
  finally { dovecotSaving.value = false }
}

const cancelDovecotEdit = () => { dovecotSettings.value = { ...dovecotOriginalSettings.value }; dovecotEditMode.value = false }

// Save raw config file for Dovecot
const saveDovecotRawConfig = async () => {
  dovecotSaving.value = true
  try {
    // Base64 encode content to bypass WAF/ModSecurity
    const encodedContent = btoa(unescape(encodeURIComponent(dovecotRawConfig.value)))
    const response = await api.put('/dovecot/config/raw', { 
      file: dovecotSelectedConfigFile.value,
      content_b64: encodedContent 
    })
    if (response.data.success) {
      toast.success('Dovecot configuration saved successfully')
      // Restart dovecot and refresh settings so guide shows updated values
      await restartDovecot()
      await fetchDovecotSettings() // Refresh settings to persist guide state
    } else toast.error(response.data.error || 'Failed to save config')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to save config') }
  finally { dovecotSaving.value = false }
}
const restartDovecot = async () => {
  dovecotSaving.value = true
  try {
    const response = await api.post('/dovecot/restart')
    if (response.data.success) { toast.success('Dovecot restarted successfully'); await fetchDovecotStatus() }
    else toast.error(response.data.error || 'Failed to restart Dovecot')
  } catch (e) { toast.error(e.response?.data?.error || 'Failed to restart Dovecot') }
  finally { dovecotSaving.value = false }
}

// ============================================
// PowerDNS State & Logic
// ============================================
const pdnsLoading = ref(true)
const pdnsSaving = ref(false)
const pdnsConfig = ref('')
const pdnsOriginalConfig = ref('')
const pdnsParsed = ref({})
const pdnsStatus = ref(null)
const pdnsConfigPath = ref('')
const pdnsEditMode = ref(false)
const pdnsRawMode = ref(false)
const pdnsSettings = ref({})
const pdnsOriginalSettings = ref({})

// DNS Stats & Sync
const dnsStats = ref(null)
const dnsStatsLoading = ref(false)
const dnsSyncing = ref(false)
const dnsSyncModal = ref(false)
const dnsSyncResult = ref(null)
const dnsSyncingZone = ref(null)

// NS Configuration
const nsConfig = ref({ enabled: true, ns1: '', ns2: '' })
const nsConfigOriginal = ref({ enabled: true, ns1: '', ns2: '' })
const nsConfigLoading = ref(false)
const nsConfigSaving = ref(false)
const nsConfigEditing = ref(false)

const pdnsSettingDefinitions = [
  // General
  { key: 'master', label: 'Master Mode', description: 'Run as master DNS server', type: 'toggle', section: 'general' },
  { key: 'slave', label: 'Slave Mode', description: 'Run as slave DNS server', type: 'toggle', section: 'general' },
  { key: 'daemon', label: 'Daemon Mode', description: 'Run as daemon', type: 'toggle', section: 'general' },
  { key: 'local-address', label: 'Listen Address', description: 'IP addresses to listen on', placeholder: '0.0.0.0', section: 'general' },
  { key: 'local-port', label: 'Listen Port', description: 'Port to listen on', placeholder: '53', section: 'general' },
  { key: 'version-string', label: 'Version String', description: 'Version shown in responses', placeholder: 'PowerDNS', section: 'general' },
  // Zone Transfer
  { key: 'allow-axfr-ips', label: 'Allow AXFR IPs', description: 'IPs allowed to request zone transfers', placeholder: '127.0.0.1', section: 'transfer' },
  { key: 'also-notify', label: 'Also Notify', description: 'IPs to notify on zone changes', placeholder: '', section: 'transfer' },
  { key: 'disable-axfr', label: 'Disable AXFR', description: 'Disable all zone transfers', type: 'toggle', section: 'transfer' },
  { key: 'allow-dnsupdate-from', label: 'Allow DNS Update From', description: 'IPs allowed to send DNS updates', placeholder: '127.0.0.1', section: 'transfer' },
  // Database Backend
  { key: 'launch', label: 'Backend', description: 'Database backend to use', section: 'database' },
  { key: 'gmysql-host', label: 'MySQL Host', description: 'Database server hostname', section: 'database' },
  { key: 'gmysql-port', label: 'MySQL Port', description: 'Database server port', section: 'database' },
  { key: 'gmysql-dbname', label: 'Database Name', description: 'Name of the DNS database', section: 'database' },
  { key: 'gmysql-user', label: 'MySQL User', description: 'Database username', section: 'database' },
  { key: 'gmysql-password', label: 'MySQL Password', description: 'Database password', type: 'password', section: 'database' },
  // Cache
  { key: 'cache-ttl', label: 'Cache TTL', description: 'Seconds to cache records', placeholder: '20', section: 'cache' },
  { key: 'negquery-cache-ttl', label: 'Negative Cache TTL', description: 'Seconds to cache negative responses', placeholder: '60', section: 'cache' },
  { key: 'query-cache-ttl', label: 'Query Cache TTL', description: 'Seconds to cache full queries', placeholder: '20', section: 'cache' },
  { key: 'max-cache-entries', label: 'Max Cache Entries', description: 'Maximum entries in cache', placeholder: '1000000', section: 'cache' },
  // Performance
  { key: 'receiver-threads', label: 'Receiver Threads', description: 'Number of receiver threads', placeholder: '1', section: 'performance' },
  { key: 'distributor-threads', label: 'Distributor Threads', description: 'Number of distributor threads', placeholder: '3', section: 'performance' },
  { key: 'reuseport', label: 'Reuse Port', description: 'Use SO_REUSEPORT for better performance', type: 'toggle', section: 'performance' },
  // Security
  { key: 'gmysql-dnssec', label: 'DNSSEC', description: 'Enable DNSSEC support', type: 'toggle', section: 'security' },
  { key: 'security-poll-suffix', label: 'Security Poll', description: 'Suffix for security polling', placeholder: '', section: 'security' },
  // API / Webserver
  { key: 'api', label: 'Enable API', description: 'Enable the REST API', type: 'toggle', section: 'api' },
  { key: 'api-key', label: 'API Key', description: 'Key for API authentication', type: 'password', section: 'api' },
  { key: 'webserver', label: 'Enable Webserver', description: 'Enable built-in webserver', type: 'toggle', section: 'api' },
  { key: 'webserver-address', label: 'Webserver Address', description: 'IP for webserver to listen on', placeholder: '127.0.0.1', section: 'api' },
  { key: 'webserver-port', label: 'Webserver Port', description: 'Port for webserver', placeholder: '8081', section: 'api' },
  { key: 'webserver-allow-from', label: 'Webserver Allow From', description: 'IPs allowed to access webserver', placeholder: '127.0.0.1', section: 'api' },
  // Logging
  { key: 'log-dns-queries', label: 'Log DNS Queries', description: 'Log all DNS queries', type: 'toggle', section: 'logging' },
  { key: 'log-dns-details', label: 'Log DNS Details', description: 'Log query details', type: 'toggle', section: 'logging' },
  { key: 'loglevel', label: 'Log Level', description: 'Logging verbosity (0-9)', placeholder: '4', section: 'logging' },
  { key: 'query-logging', label: 'Query Logging', description: 'Enable query logging to backend', type: 'toggle', section: 'logging' },
]

const pdnsSections = [
  { id: 'general', label: 'General Settings', icon: 'settings' },
  { id: 'transfer', label: 'Zone Transfers', icon: 'sync' },
  { id: 'database', label: 'Database Backend', icon: 'storage' },
  { id: 'cache', label: 'Caching', icon: 'cached' },
  { id: 'performance', label: 'Performance', icon: 'speed' },
  { id: 'security', label: 'Security', icon: 'security' },
  { id: 'api', label: 'API / Webserver', icon: 'api' },
  { id: 'logging', label: 'Logging', icon: 'description' },
]

const getPdnsSettingsBySection = (sectionId) => {
  return pdnsSettingDefinitions.filter(def => def.section === sectionId)
}

const pdnsHasChanges = computed(() => {
  return JSON.stringify(pdnsSettings.value) !== JSON.stringify(pdnsOriginalSettings.value)
})

const fetchPdnsConfig = async () => {
  pdnsLoading.value = true
  try {
    const [configResponse, statusResponse] = await Promise.all([
      api.get('/system/pdns'),
      api.get('/system/pdns/status')
    ])
    if (configResponse.data.success) {
      pdnsConfig.value = configResponse.data.data.raw
      pdnsOriginalConfig.value = configResponse.data.data.raw
      pdnsParsed.value = configResponse.data.data.parsed || {}
      pdnsConfigPath.value = configResponse.data.data.config_path
      // Populate settings from parsed config
      pdnsSettings.value = { ...configResponse.data.data.parsed }
      pdnsOriginalSettings.value = { ...configResponse.data.data.parsed }
    }
    if (statusResponse.data.success) {
      pdnsStatus.value = statusResponse.data.data
    }
  } catch (e) {
    toast.error('Failed to load PowerDNS configuration')
  } finally {
    pdnsLoading.value = false
  }
}

const savePdnsConfig = async () => {
  pdnsSaving.value = true
  try {
    const response = await api.put('/system/pdns', { config: pdnsConfig.value })
    if (response.data.success) {
      toast.success('PowerDNS configuration saved and service restarted')
      pdnsOriginalConfig.value = pdnsConfig.value
      pdnsEditMode.value = false
      const statusResponse = await api.get('/system/pdns/status')
      if (statusResponse.data.success) pdnsStatus.value = statusResponse.data.data
    } else {
      toast.error(response.data.error || 'Failed to save configuration')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save configuration')
  } finally {
    pdnsSaving.value = false
  }
}

const restartPdns = async () => {
  pdnsSaving.value = true
  try {
    const response = await api.post('/system/pdns/restart')
    if (response.data.success) {
      toast.success('PowerDNS restarted')
      const statusResponse = await api.get('/system/pdns/status')
      if (statusResponse.data.success) pdnsStatus.value = statusResponse.data.data
    } else {
      toast.error(response.data.error || 'Failed to restart PowerDNS')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restart PowerDNS')
  } finally {
    pdnsSaving.value = false
  }
}

// Fetch DNS server stats (nameservers, zone count, etc.)
const fetchDnsStats = async () => {
  dnsStatsLoading.value = true
  try {
    const response = await api.get('/dns/stats')
    if (response.data.success) {
      dnsStats.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to load DNS stats', e)
    dnsStats.value = null
  } finally {
    dnsStatsLoading.value = false
  }
}

// Fetch NS configuration
const fetchNsConfig = async () => {
  nsConfigLoading.value = true
  try {
    const response = await api.get('/dns/ns-config')
    if (response.data.success) {
      nsConfig.value = { ...response.data.data }
      nsConfigOriginal.value = { ...response.data.data }
    }
  } catch (e) {
    console.error('Failed to load NS config', e)
  } finally {
    nsConfigLoading.value = false
  }
}

// Save NS configuration
const saveNsConfig = async () => {
  nsConfigSaving.value = true
  try {
    const response = await api.put('/dns/ns-config', nsConfig.value)
    if (response.data.success) {
      toast.success('Nameserver configuration saved')
      nsConfigOriginal.value = { ...nsConfig.value }
      nsConfigEditing.value = false
      // Refresh DNS stats to show updated nameservers
      fetchDnsStats()
    } else {
      toast.error(response.data.error || 'Failed to save nameserver configuration')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save nameserver configuration')
  } finally {
    nsConfigSaving.value = false
  }
}

// Cancel NS config editing
const cancelNsConfigEdit = () => {
  nsConfig.value = { ...nsConfigOriginal.value }
  nsConfigEditing.value = false
}

// Check if NS config has changes
const nsConfigHasChanges = computed(() => {
  return nsConfig.value.enabled !== nsConfigOriginal.value.enabled ||
         nsConfig.value.ns1 !== nsConfigOriginal.value.ns1 ||
         nsConfig.value.ns2 !== nsConfigOriginal.value.ns2
})

// Sync all DNS zones to slave nameservers
const syncAllDnsZones = async () => {
  dnsSyncing.value = true
  dnsSyncResult.value = null
  dnsSyncModal.value = true
  
  try {
    const response = await api.post('/dns/sync-all')
    if (response.data.success) {
      const data = response.data.data
      dnsSyncResult.value = {
        success: true,
        zones_synced: data.zones_synced || 0,
        zones_failed: data.zones_failed || [],
        total_zones: data.total_zones || 0,
        last_sync: data.last_sync
      }
      toast.success(`Synced ${data.zones_synced || 0} zones to slave nameservers`)
      // Refresh stats after sync
      await fetchDnsStats()
    } else {
      dnsSyncResult.value = { success: false, error: response.data.error || 'Failed to sync' }
      toast.error(response.data.error || 'Failed to sync DNS zones')
    }
  } catch (e) {
    dnsSyncResult.value = { success: false, error: e.response?.data?.error || 'Failed to sync DNS zones' }
    toast.error(e.response?.data?.error || 'Failed to sync DNS zones')
  } finally {
    dnsSyncing.value = false
  }
}

// Sync individual zone
const syncSingleZone = async (zoneName) => {
  dnsSyncingZone.value = zoneName
  try {
    const response = await api.post(`/dns/zones/${zoneName}/sync`)
    if (response.data.success) {
      toast.success(`Zone ${zoneName} synced successfully`)
      // Remove from failed list if it was there
      if (dnsSyncResult.value?.zones_failed) {
        dnsSyncResult.value.zones_failed = dnsSyncResult.value.zones_failed.filter(z => z !== zoneName)
        dnsSyncResult.value.zones_synced++
      }
    } else {
      toast.error(response.data.error || `Failed to sync ${zoneName}`)
    }
  } catch (e) {
    toast.error(e.response?.data?.error || `Failed to sync ${zoneName}`)
  } finally {
    dnsSyncingZone.value = null
  }
}

const cancelPdnsEdit = () => {
  pdnsConfig.value = pdnsOriginalConfig.value
  pdnsSettings.value = { ...pdnsOriginalSettings.value }
  pdnsEditMode.value = false
  pdnsRawMode.value = false
}

const savePdnsSettings = async () => {
  pdnsSaving.value = true
  try {
    // Build config string from settings
    let configLines = []
    for (const [key, value] of Object.entries(pdnsSettings.value)) {
      if (value !== undefined && value !== null && value !== '') {
        configLines.push(`${key}=${value}`)
      }
    }
    const newConfig = configLines.join('\n') + '\n'
    
    const response = await api.put('/system/pdns', { config: newConfig })
    if (response.data.success) {
      toast.success('PowerDNS configuration saved and service restarted')
      pdnsConfig.value = newConfig
      pdnsOriginalConfig.value = newConfig
      pdnsOriginalSettings.value = { ...pdnsSettings.value }
      pdnsEditMode.value = false
      const statusResponse = await api.get('/system/pdns/status')
      if (statusResponse.data.success) pdnsStatus.value = statusResponse.data.data
    } else {
      toast.error(response.data.error || 'Failed to save configuration')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save configuration')
  } finally {
    pdnsSaving.value = false
  }
}

// ============================================
// Logs State & Logic
// ============================================
const logsLoading = ref(false)
const logsService = ref('openlitespeed')
const logsType = ref('journalctl')
const logsFilter = ref('')
const logsSearch = ref('')
const logsLines = ref([])
const logsTotal = ref(0)
const logsAvailableTypes = ref([])
const logsAvailableFilters = ref({})
const logsPhpVersion = ref('')

// Logs AI Assistant
const logsAiPanelOpen = ref(false)
const logsAiMessages = ref([])
const logsAiTyping = ref(false)
const logsAiInput = ref('')
const logsAiConversationId = ref(null)
const logsSelectedLines = ref([])
const logsAiLoading = ref(false)

const logsServices = [
  { id: 'openlitespeed', label: 'OpenLiteSpeed', icon: 'bolt' },
  { id: 'php', label: 'PHP', icon: 'code' },
  { id: 'mysql', label: 'MySQL', icon: 'database' },
  { id: 'postfix', label: 'Postfix', icon: 'forward_to_inbox' },
  { id: 'dovecot', label: 'Dovecot', icon: 'inbox' },
  { id: 'mailsync-server', label: 'Mailsync', icon: 'sync' },
  { id: 'collab-server', label: 'Collab', icon: 'edit_note' },
]

const fetchLogTypes = async () => {
  try {
    const response = await api.get(`/system-logs/${logsService.value}/types`)
    if (response.data.success) {
      logsAvailableTypes.value = response.data.data.types || []
      logsAvailableFilters.value = response.data.data.filters || {}
      if (logsAvailableTypes.value.length > 0) {
        const journalType = logsAvailableTypes.value.find(t => t.id === 'journalctl' && t.exists)
        if (journalType) logsType.value = 'journalctl'
        else {
          const existingType = logsAvailableTypes.value.find(t => t.exists)
          if (existingType) logsType.value = existingType.id
          else logsType.value = logsAvailableTypes.value[0].id
        }
      }
    }
  } catch (e) { console.error('Failed to load log types', e) }
}

const fetchLogs = async () => {
  logsLoading.value = true
  try {
    const params = { type: logsType.value, lines: 200 }
    if (logsFilter.value) params.filter = logsFilter.value
    if (logsSearch.value) params.search = logsSearch.value
    if (logsService.value === 'php' && logsPhpVersion.value) params.version = logsPhpVersion.value
    const response = await api.get(`/system-logs/${logsService.value}`, { params })
    if (response.data.success) {
      logsLines.value = response.data.data.lines || []
      logsTotal.value = response.data.data.total || 0
    }
  } catch (e) { toast.error('Failed to load logs') }
  finally { logsLoading.value = false }
}

const loadLogsTab = async () => {
  logsLoading.value = true
  if (filteredPhpVersions.value.length > 0 && !logsPhpVersion.value) logsPhpVersion.value = filteredPhpVersions.value[0].version
  await fetchLogTypes()
  await fetchLogs()
}

const changeLogsService = async (service) => {
  logsService.value = service
  logsFilter.value = ''
  logsSearch.value = ''
  await fetchLogTypes()
  await fetchLogs()
}

const applyLogsFilter = (filter) => {
  logsFilter.value = logsFilter.value === filter ? '' : filter
  fetchLogs()
}

// Logs AI Functions
const toggleLogsAiPanel = () => {
  logsAiPanelOpen.value = !logsAiPanelOpen.value
  if (!logsAiPanelOpen.value) {
    logsSelectedLines.value = []
  }
}

const toggleLogLineSelection = (index) => {
  const idx = logsSelectedLines.value.indexOf(index)
  if (idx > -1) {
    logsSelectedLines.value.splice(idx, 1)
  } else {
    logsSelectedLines.value.push(index)
  }
}

const selectAllVisibleErrors = () => {
  logsSelectedLines.value = []
  logsLines.value.forEach((line, index) => {
    const parsed = parseLogLine(line)
    if (['ERR', 'CRIT', 'ALERT', 'EMERG', 'ERROR', 'FATAL'].includes(parsed.level?.toUpperCase())) {
      logsSelectedLines.value.push(index)
    }
  })
}

const clearLogSelection = () => {
  logsSelectedLines.value = []
}

const sendSelectedLogsToAi = async () => {
  if (logsSelectedLines.value.length === 0) {
    toast.error('Select some log lines first')
    return
  }
  
  const selectedLogs = logsSelectedLines.value
    .sort((a, b) => a - b)
    .map(i => logsLines.value[i])
    .join('\n')
  
  const displayMsg = `Analyze these ${logsSelectedLines.value.length} log entries`
  const aiMsg = `Analyze these ${logsService.value} log entries. For each error:\n1. Explain what caused it (brief)\n2. Provide exact fix command or solution\n3. If PHP error, show code fix\n\nLogs:\n\`\`\`\n${selectedLogs}\n\`\`\``
  
  await sendLogsAiMessage(displayMsg, aiMsg)
  logsSelectedLines.value = []
}

const sendLogsAiMessage = async (displayMessage, aiMessage) => {
  // Add clean message to chat
  logsAiMessages.value.push({ role: 'user', content: displayMessage })
  
  logsAiTyping.value = true
  try {
    // Create conversation if needed
    if (!logsAiConversationId.value) {
      const conversation = await aiHelper.createConversation(`Logs: ${logsService.value}`)
      logsAiConversationId.value = conversation.id
    }
    
    const context = {
      type: 'log_analysis',
      service: logsService.value,
      logType: logsType.value
    }
    
    const response = await aiHelper.sendMessage(logsAiConversationId.value, aiMessage, context)
    logsAiMessages.value.push({ 
      role: 'assistant', 
      content: response.message || 'Could not analyze the logs.' 
    })
  } catch (e) {
    console.error('AI message error:', e)
    logsAiMessages.value.push({ 
      role: 'assistant', 
      content: 'Error communicating with AI. Please try again.' 
    })
  } finally {
    logsAiTyping.value = false
  }
}

const sendLogsAiChat = async () => {
  if (!logsAiInput.value.trim() || logsAiTyping.value) return
  
  const message = logsAiInput.value.trim()
  logsAiInput.value = ''
  
  await sendLogsAiMessage(message, message)
}

const handleLogsAiKeydown = (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    sendLogsAiChat()
  }
}

const runAiCommand = async (command) => {
  // This would execute a command suggested by AI
  // For now, just copy to clipboard
  await copyToClipboard(command, 'Command copied to clipboard')
}

// Safe clipboard copy function for inline onclick handlers (with fallback)
const safeCopyScript = `(function(btn){try{var t=btn.previousElementSibling.textContent.trim();if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t)}else{var a=document.createElement('textarea');a.value=t;a.style.position='fixed';a.style.left='-9999px';document.body.appendChild(a);a.select();document.execCommand('copy');document.body.removeChild(a)}btn.textContent='Copied!';setTimeout(function(){btn.textContent='Copy'},1500)}catch(e){console.error('Copy failed',e)}})(this)`

const renderLogsAiMarkdown = (text) => {
  try {
    let processed = text
      // Command blocks - make them clickable (with safe clipboard fallback)
      .replace(/```bash\n([\s\S]*?)```/g, `<pre class="command-block"><code>$1</code><button class="copy-cmd-btn" onclick="${safeCopyScript}">Copy</button></pre>`)
      .replace(/```sh\n([\s\S]*?)```/g, `<pre class="command-block"><code>$1</code><button class="copy-cmd-btn" onclick="${safeCopyScript}">Copy</button></pre>`)
      // Section headers
      .replace(/\*\*CAUSE:\*\*/gi, '<div class="ai-log-section cause"><span class="material-symbols-rounded">help</span><strong>Cause</strong></div>')
      .replace(/\*\*FIX:\*\*/gi, '<div class="ai-log-section fix"><span class="material-symbols-rounded">build</span><strong>Fix</strong></div>')
      .replace(/\*\*COMMAND:\*\*/gi, '<div class="ai-log-section command"><span class="material-symbols-rounded">terminal</span><strong>Command</strong></div>')
      .replace(/\*\*SOLUTION:\*\*/gi, '<div class="ai-log-section fix"><span class="material-symbols-rounded">check_circle</span><strong>Solution</strong></div>')
    
    return marked.parse(processed)
  } catch (e) {
    return text.replace(/\n/g, '<br>')
  }
}

const parseLogLine = (line) => {
  const parsed = { raw: line, timestamp: null, level: 'info', message: line }
  const timestampPatterns = [/^(\w{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})\s+/, /^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s*/]
  for (const pattern of timestampPatterns) {
    const match = line.match(pattern)
    if (match) { parsed.timestamp = match[1]; parsed.message = line.substring(match[0].length); break }
  }
  const lowerLine = line.toLowerCase()
  if (lowerLine.includes('error') || lowerLine.includes('fatal')) parsed.level = 'error'
  else if (lowerLine.includes('warn')) parsed.level = 'warning'
  else if (lowerLine.includes('success') || lowerLine.includes('delivered')) parsed.level = 'success'
  return parsed
}

const getLogLevelClasses = (level) => {
  switch (level) {
    case 'error': return 'border-l-red-500 bg-red-500/5'
    case 'warning': return 'border-l-amber-500 bg-amber-500/5'
    case 'success': return 'border-l-green-500 bg-green-500/5'
    default: return 'border-l-blue-500 bg-blue-500/5'
  }
}

const getLogLevelColor = (level) => {
  switch (level) {
    case 'error': return 'text-red-400'
    case 'warning': return 'text-amber-400'
    case 'success': return 'text-green-400'
    default: return 'text-surface-400'
  }
}

const getLogLevelBadge = (level) => {
  const badges = {
    error: { text: 'ERR', class: 'bg-red-500/20 text-red-400' },
    warning: { text: 'WRN', class: 'bg-amber-500/20 text-amber-400' },
    success: { text: 'OK', class: 'bg-green-500/20 text-green-400' },
    info: { text: 'INF', class: 'bg-blue-500/20 text-blue-400' },
  }
  return badges[level] || { text: 'LOG', class: 'bg-surface-500/20 text-surface-400' }
}

// ============================================
// Format bytes helper
// ============================================
const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let i = 0
  while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++ }
  return `${bytes.toFixed(1)} ${units[i]}`
}

// ============================================
// Config Permissions State & Logic
// ============================================
const permissionsData = ref({})
const permissionsLoading = ref({})
const permissionsFixing = ref({})

const fetchPermissions = async (service) => {
  permissionsLoading.value[service] = true
  try {
    const response = await api.get(`/system/permissions/${service}`)
    if (response.data.success) {
      permissionsData.value[service] = response.data.data
    }
  } catch (e) {
    console.error(`Failed to fetch ${service} permissions`, e)
  } finally {
    permissionsLoading.value[service] = false
  }
}

const fixPermissions = async (service) => {
  permissionsFixing.value[service] = true
  try {
    const response = await api.post(`/system/permissions/${service}/fix`)
    if (response.data.success) {
      toast.success(`${response.data.data.name || service} permissions fixed`)
      permissionsData.value[service] = response.data.data.status
    } else {
      toast.error(response.data.error || 'Failed to fix permissions')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to fix permissions')
  } finally {
    permissionsFixing.value[service] = false
  }
}

const getPermissionStatus = (service) => {
  const data = permissionsData.value[service]
  if (!data) return null
  return data
}

// ============================================
// Email App Services State & Logic
// ============================================
const emailAppLoading = ref(false)
const emailAppServices = ref([])
const emailAppActionLoading = ref({})
const emailAppLogs = ref({})
const emailAppLogsLoading = ref({})
const emailAppLogsExpanded = ref({})

// Config file editing state
const emailAppConfigMode = ref(false)
const emailAppSelectedService = ref('mailsync-server')
const emailAppRawConfig = ref('')
const emailAppConfigLoading = ref(false)
const emailAppConfigSaving = ref(false)

// Config files for email app services
const emailAppConfigFiles = [
  { service: 'mailsync-server', path: '/etc/systemd/system/mailsync-server.service', label: 'mailsync-server.service', description: 'Mailsync server systemd unit' },
  { service: 'collab-server', path: '/etc/systemd/system/collab-server.service', label: 'collab-server.service', description: 'Collab server systemd unit' },
]

// Email app service definitions with metadata
const emailAppServiceDefs = [
  {
    name: 'mailsync-server',
    port: 1235,
    description: 'Real-time email sync WebSocket (IMAP IDLE, Redis pub/sub)',
    icon: 'sync',
    color: 'cyan'
  },
  {
    name: 'collab-server',
    port: 1234,
    description: 'Collaborative document editing WebSocket (Hocuspocus)',
    icon: 'edit_note',
    color: 'purple'
  }
]

// Infrastructure services used by email app
const emailAppInfraServices = ['lsws', 'redis', 'mariadb', 'dovecot', 'postfix']

const emailAppConfigPath = computed(() => {
  const file = emailAppConfigFiles.find(f => f.service === emailAppSelectedService.value)
  return file?.path || ''
})

const fetchEmailAppServices = async () => {
  emailAppLoading.value = true
  try {
    const response = await api.get('/services')
    if (response.data.success) {
      const allServices = response.data.data.services || []
      // Filter to only email app related services
      const emailAppNames = ['mailsync-server', 'collab-server', ...emailAppInfraServices]
      emailAppServices.value = allServices.filter(s => emailAppNames.includes(s.name))
    }
  } catch (e) {
    toast.error('Failed to load Email App services')
  } finally {
    emailAppLoading.value = false
  }
}

const performEmailAppServiceAction = async (service, action) => {
  emailAppActionLoading.value[service.name] = action
  try {
    const response = await api.post(`/services/${service.name}/${action}`)
    if (response.data.success) {
      toast.success(response.data.message || `Service ${action}ed`)
      await fetchEmailAppServices()
    } else {
      toast.error(response.data.error || `Failed to ${action} service`)
    }
  } catch (e) {
    toast.error(e.response?.data?.error || `Failed to ${action} service`)
  } finally {
    delete emailAppActionLoading.value[service.name]
  }
}

const fetchEmailAppLogs = async (serviceName, lines = 50) => {
  emailAppLogsLoading.value[serviceName] = true
  try {
    const response = await api.get(`/services/${serviceName}/logs?lines=${lines}`)
    if (response.data.success) {
      emailAppLogs.value[serviceName] = response.data.data
    }
  } catch (e) {
    console.error(`Failed to fetch ${serviceName} logs`)
  } finally {
    emailAppLogsLoading.value[serviceName] = false
  }
}

const toggleEmailAppLogs = async (serviceName) => {
  emailAppLogsExpanded.value[serviceName] = !emailAppLogsExpanded.value[serviceName]
  if (emailAppLogsExpanded.value[serviceName] && !emailAppLogs.value[serviceName]) {
    await fetchEmailAppLogs(serviceName)
  }
}

const getEmailAppServiceDef = (name) => {
  return emailAppServiceDefs.find(s => s.name === name) || {}
}

const getEmailAppService = (name) => {
  return emailAppServices.value.find(s => s.name === name)
}

// Load raw config for email app service
const loadEmailAppConfig = async (service) => {
  emailAppConfigLoading.value = true
  const file = emailAppConfigFiles.find(f => f.service === service)
  if (!file) return
  
  try {
    const response = await api.get('/files/read', { params: { path: file.path } })
    if (response.data.success) {
      emailAppRawConfig.value = response.data.data.content || ''
    } else {
      toast.error(response.data.error || 'Failed to load config')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to load config')
  } finally {
    emailAppConfigLoading.value = false
  }
}

// Save raw config for email app service
const saveEmailAppConfig = async () => {
  emailAppConfigSaving.value = true
  const file = emailAppConfigFiles.find(f => f.service === emailAppSelectedService.value)
  if (!file) return
  
  try {
    const encodedContent = btoa(unescape(encodeURIComponent(emailAppRawConfig.value)))
    const response = await api.post('/files/write', { 
      path: file.path,
      content_b64: encodedContent 
    })
    if (response.data.success) {
      toast.success('Config saved successfully')
      // Offer to reload systemd daemon
      toast.info('Run "systemctl daemon-reload" to apply changes')
    } else {
      toast.error(response.data.error || 'Failed to save config')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save config')
  } finally {
    emailAppConfigSaving.value = false
  }
}

// Watch for config mode toggle
watch(emailAppConfigMode, async (newVal) => {
  if (newVal && !emailAppRawConfig.value) {
    await loadEmailAppConfig(emailAppSelectedService.value)
  }
})

// Watch for service change while in config mode
watch(emailAppSelectedService, async (newService) => {
  if (emailAppConfigMode.value) {
    await loadEmailAppConfig(newService)
  }
})

// ============================================
// Load data based on active tab
// ============================================
const loadTabData = (tab) => {
  switch (tab) {
    case 'overview': if (!systemInfo.value) fetchSystemInfo(); break
    case 'hostname': if (!currentHostname.value) fetchHostname(); break
    case 'timezone': if (!currentTimezone.value) fetchTimezone(); break
    case 'ssh': 
      if (Object.keys(sshSettings.value).length === 0) fetchSshSettings()
      if (!permissionsData.value.ssh) fetchPermissions('ssh')
      break
    case 'swap': if (!swapInfo.value) fetchSwapInfo(); break
    case 'motd': if (!motdData.value) fetchMotd(); break
    case 'templates': if (!templatesData.value) fetchTemplates(); break
    case 'ols': 
      if (!olsStatus.value) fetchOlsStatus()
      if (Object.keys(olsSettings.value).length === 0) fetchOlsSettings()
      if (!permissionsData.value.ols) fetchPermissions('ols')
      fetchSiteCount()
      break
    case 'php': 
      if (filteredPhpVersions.value.length === 0) fetchPhpVersions()
      if (!permissionsData.value.php) fetchPermissions('php')
      fetchSiteCount()
      break
    case 'mysql': 
      if (!mysqlStatus.value) fetchMysqlStatus()
      if (mysqlVariables.value.length === 0) fetchMysqlSettings()
      if (!permissionsData.value.mysql) fetchPermissions('mysql')
      break
    case 'postfix': 
      if (!postfixStatus.value) fetchPostfixStatus()
      if (Object.keys(postfixSettings.value).length === 0) fetchPostfixSettings()
      if (!permissionsData.value.postfix) fetchPermissions('postfix')
      break
    case 'dovecot': 
      if (!dovecotStatus.value) fetchDovecotStatus()
      if (Object.keys(dovecotSettings.value).length === 0) fetchDovecotSettings()
      if (!permissionsData.value.dovecot) fetchPermissions('dovecot')
      break
    case 'pdns': 
      if (!pdnsConfig.value) fetchPdnsConfig()
      if (!permissionsData.value.pdns) fetchPermissions('pdns')
      if (!dnsStats.value) fetchDnsStats()
      if (!nsConfig.value.ns1) fetchNsConfig()
      break
    case 'logs': loadLogsTab(); break
    case 'emailapp': 
      if (emailAppServices.value.length === 0) fetchEmailAppServices()
      break
  }
}

watch(activeTab, (newTab) => loadTabData(newTab), { immediate: true })
onMounted(() => loadTabData(activeTab.value))
</script>

<template>
  <div>
    <!-- Header -->
    <div class="page-header">
      <div>
        <h1 class="page-title">System</h1>
        <p class="text-surface-500 text-sm mt-1 hidden sm:block">Manage system settings, services, and server configuration</p>
      </div>
      <button @click="showRebootModal = true" class="btn-danger shrink-0">
        <span class="material-symbols-rounded">restart_alt</span>
        <span class="hidden sm:inline">Reboot Server</span>
      </button>
    </div>

    <!-- Tabs -->
    <div class="relative border-b border-surface-200 dark:border-surface-700 mb-6">
      <!-- Scroll left button - always show when scrollable -->
      <button 
        @click="scrollTabs('left')"
        :class="[
          'absolute left-0 top-0 bottom-0 z-20 w-10 flex items-center justify-center transition-opacity',
          'bg-gradient-to-r from-surface-100 via-surface-100/90 dark:from-surface-950 dark:via-surface-950/90 to-transparent',
          canScrollLeft ? 'opacity-100' : 'opacity-0 pointer-events-none'
        ]"
      >
        <span class="material-symbols-rounded text-surface-600 dark:text-surface-400 bg-surface-200 dark:bg-surface-800 rounded-full p-0.5">chevron_left</span>
      </button>
      
      <nav ref="tabsNav" class="flex overflow-x-auto scrollbar-none mx-8" @scroll="updateScrollIndicators">
        <template v-for="(group, groupIndex) in tabGroups" :key="group.name">
          <!-- Group separator (not before first group) -->
          <div v-if="groupIndex > 0" class="flex items-center px-2 shrink-0">
            <div class="h-6 w-px bg-surface-300 dark:bg-surface-600"></div>
          </div>
          
          <button
            v-for="tab in group.tabs"
            :key="tab.id"
            @click="activeTab = tab.id"
            :class="[
              'flex items-center gap-1 px-2 lg:px-3 py-2 text-xs font-medium border-b-2 transition-colors whitespace-nowrap shrink-0',
              activeTab === tab.id
                ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
            ]"
            :title="tab.label"
          >
            <span class="material-symbols-rounded text-base">{{ tab.icon }}</span>
            <span class="hidden lg:inline text-xs">{{ tab.label }}</span>
          </button>
        </template>
      </nav>
      
      <!-- Scroll right button - always show when scrollable -->
      <button 
        @click="scrollTabs('right')"
        :class="[
          'absolute right-0 top-0 bottom-0 z-20 w-10 flex items-center justify-center transition-opacity',
          'bg-gradient-to-l from-surface-100 via-surface-100/90 dark:from-surface-950 dark:via-surface-950/90 to-transparent',
          canScrollRight ? 'opacity-100' : 'opacity-0 pointer-events-none'
        ]"
      >
        <span class="material-symbols-rounded text-surface-600 dark:text-surface-400 bg-surface-200 dark:bg-surface-800 rounded-full p-0.5">chevron_right</span>
      </button>
    </div>

    <!-- Overview Tab -->
    <div v-if="activeTab === 'overview'" class="space-y-6">
      <div v-if="systemLoading" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading system information...</span>
        </div>
      </div>
      <template v-else-if="systemInfo">
        <div class="card p-4 sm:p-6">
          <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">computer</span>
            System Information
          </h3>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
            <div><p class="text-xs sm:text-sm text-surface-500">Hostname</p><p class="font-medium font-mono text-sm sm:text-base truncate">{{ systemInfo.hostname }}</p></div>
            <div><p class="text-xs sm:text-sm text-surface-500">OS</p><p class="font-medium text-sm sm:text-base truncate">{{ systemInfo.os?.pretty_name }}</p></div>
            <div><p class="text-xs sm:text-sm text-surface-500">Kernel</p><p class="font-medium font-mono text-sm sm:text-base truncate">{{ systemInfo.kernel }}</p></div>
            <div><p class="text-xs sm:text-sm text-surface-500">Uptime</p><p class="font-medium text-sm sm:text-base">{{ systemInfo.uptime?.formatted }}</p></div>
          </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
          <div class="card p-4 sm:p-6">
            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-green-500">memory_alt</span>Memory
            </h3>
            <div class="space-y-4">
              <div class="flex justify-between text-xs sm:text-sm"><span>Used: {{ systemInfo.memory?.used_human }}</span><span>Total: {{ systemInfo.memory?.total_human }}</span></div>
              <div class="w-full bg-surface-200 dark:bg-surface-700 rounded-full h-3">
                <div class="bg-green-500 h-3 rounded-full transition-all" :style="{ width: systemInfo.memory?.percent_used + '%' }"/>
              </div>
            </div>
          </div>
          <div class="card p-4 sm:p-6">
            <h3 class="text-lg font-semibold mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-amber-500">hard_drive</span>Disk Usage (/)
            </h3>
            <div class="space-y-4">
              <div class="flex justify-between text-xs sm:text-sm"><span>Used: {{ systemInfo.disk?.used_human }}</span><span>Total: {{ systemInfo.disk?.total_human }}</span></div>
              <div class="w-full bg-surface-200 dark:bg-surface-700 rounded-full h-3">
                <div :class="['h-3 rounded-full', systemInfo.disk?.percent_used > 90 ? 'bg-red-500' : systemInfo.disk?.percent_used > 70 ? 'bg-amber-500' : 'bg-green-500']" :style="{ width: systemInfo.disk?.percent_used + '%' }"/>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- Hostname Tab -->
    <div v-if="activeTab === 'hostname'" class="space-y-6">
      <div v-if="hostnameLoading" class="card p-8 sm:p-12"><div class="flex items-center justify-center gap-3 text-surface-500"><span class="spinner"></span><span>Loading hostname...</span></div></div>
      <div v-else class="card p-4 sm:p-6">
        <h3 class="text-lg font-semibold mb-6 flex items-center gap-2"><span class="material-symbols-rounded text-primary-500">dns</span>Server Hostname</h3>
        <div v-if="!hostnameEditMode" class="space-y-4">
          <div><p class="text-sm text-surface-500 mb-1">Current Hostname</p><p class="text-xl sm:text-2xl font-mono font-medium break-all">{{ currentHostname }}</p></div>
          <div><p class="text-sm text-surface-500 mb-1">FQDN</p><p class="font-mono break-all">{{ currentFqdn }}</p></div>
          <button @click="hostnameEditMode = true" class="btn-primary mt-4 w-full sm:w-auto"><span class="material-symbols-rounded">edit</span>Change Hostname</button>
        </div>
        <div v-else class="space-y-4">
          <div><label class="block text-sm font-medium mb-2">New Hostname</label><input v-model="newHostname" type="text" class="input sm:max-w-md" placeholder="server.example.com"/></div>
          <div class="flex flex-col sm:flex-row gap-3">
            <button @click="hostnameEditMode = false" class="btn-secondary w-full sm:w-auto">Cancel</button>
            <button @click="saveHostname" class="btn-primary w-full sm:w-auto" :disabled="hostnameSaving || newHostname === currentHostname"><span v-if="hostnameSaving" class="spinner"></span>Save Hostname</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Timezone Tab -->
    <div v-if="activeTab === 'timezone'" class="space-y-6">
      <div v-if="timezoneLoading" class="card p-8 sm:p-12"><div class="flex items-center justify-center gap-3 text-surface-500"><span class="spinner"></span><span>Loading timezone...</span></div></div>
      <div v-else class="card p-4 sm:p-6">
        <h3 class="text-lg font-semibold mb-6 flex items-center gap-2"><span class="material-symbols-rounded text-primary-500">schedule</span>Server Timezone</h3>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6 mb-6">
          <div><p class="text-sm text-surface-500 mb-1">Current Timezone</p><p class="text-lg sm:text-xl font-medium truncate">{{ currentTimezone }}</p></div>
          <div><p class="text-sm text-surface-500 mb-1">UTC Offset</p><p class="text-lg sm:text-xl font-mono">{{ currentOffset }}</p></div>
          <div><p class="text-sm text-surface-500 mb-1">Server Time</p><p class="text-lg sm:text-xl font-mono">{{ currentTime }}</p></div>
        </div>
        <div class="space-y-4">
          <div><label class="block text-sm font-medium mb-2">Change Timezone</label><input v-model="timezoneSearch" type="text" class="input sm:max-w-md mb-2" placeholder="Search timezones..."/><select v-model="newTimezone" class="input sm:max-w-md"><option v-for="tz in filteredTimezones" :key="tz" :value="tz">{{ tz }}</option></select></div>
          <button @click="saveTimezone" class="btn-primary w-full sm:w-auto" :disabled="timezoneSaving || newTimezone === currentTimezone"><span v-if="timezoneSaving" class="spinner"></span>Update Timezone</button>
        </div>
      </div>
    </div>

    <!-- SSH Tab -->
    <div v-if="activeTab === 'ssh'" class="space-y-6">
      <!-- Header with config path -->
      <div class="card p-4 sm:p-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
          <div>
            <h3 class="text-lg font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-green-500">terminal</span>
              SSH Configuration
            </h3>
            <div class="flex items-center gap-2 mt-2">
              <code class="text-xs sm:text-sm bg-surface-100 dark:bg-surface-800 px-2 sm:px-3 py-1 rounded-lg font-mono text-surface-600 dark:text-surface-400 truncate max-w-[200px] sm:max-w-none">{{ sshConfigPath }}</code>
              <button @click="copyToClipboard(sshConfigPath)" class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-800 rounded-lg transition-colors shrink-0">
                <span class="material-symbols-rounded text-lg text-surface-500">content_copy</span>
              </button>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button @click="openGuide('ssh')" class="btn-secondary btn-sm sm:btn" title="Best Practices Guide">
              <span class="material-symbols-rounded">lightbulb</span>
              <span class="hidden sm:inline">Guide</span>
            </button>
            <button @click="sshRawMode = !sshRawMode" :class="['btn-secondary btn-sm sm:btn', sshRawMode && 'bg-surface-200 dark:bg-surface-700']">
              <span class="material-symbols-rounded">code</span>
              <span class="hidden sm:inline">Raw Edit</span>
            </button>
            <button @click="sshEditMode = !sshEditMode; sshRawMode = false" :class="['btn-primary btn-sm sm:btn', sshEditMode && !sshRawMode && 'bg-primary-600']">
              <span class="material-symbols-rounded">edit</span>
              <span class="hidden sm:inline">Edit Values</span>
            </button>
          </div>
        </div>
        
        <!-- Status bar -->
        <div class="flex items-center justify-between flex-wrap gap-4 pt-4 border-t border-surface-200 dark:border-surface-700">
          <div class="flex items-center gap-6 flex-wrap">
            <div class="flex items-center gap-2">
              <span :class="['w-2 h-2 rounded-full', sshSettings.service_status === 'active' ? 'bg-green-500' : 'bg-red-500']"></span>
              <span class="text-sm font-medium">{{ sshSettings.service_status === 'active' ? 'Running' : 'Stopped' }}</span>
            </div>
            <div v-if="sshSettings.port" class="text-sm text-surface-500">Port: <span class="font-mono">{{ sshSettings.port }}</span></div>
          </div>
        </div>
      </div>

      <!-- Warning -->
      <div class="card p-4 bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/20">
        <div class="flex gap-3">
          <span class="material-symbols-rounded text-amber-500">warning</span>
          <div>
            <p class="font-medium text-amber-700 dark:text-amber-400">Be careful with SSH settings</p>
            <p class="text-sm text-amber-600 dark:text-amber-300">Incorrect SSH configuration may lock you out of the server.</p>
          </div>
        </div>
      </div>

      <!-- Config Permissions Card -->
      <div v-if="permissionsData.ssh" class="card p-4" :class="permissionsData.ssh.ok ? 'border-l-4 border-l-green-500' : 'border-l-4 border-l-red-500'">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span :class="['material-symbols-rounded text-xl', permissionsData.ssh.ok ? 'text-green-500' : 'text-red-500']">{{ permissionsData.ssh.ok ? 'check_circle' : 'error' }}</span>
            <div>
              <p class="font-medium">Config File Permissions</p>
              <p class="text-sm text-surface-500">
                <template v-if="permissionsData.ssh.ok">All config files have correct permissions</template>
                <template v-else>{{ permissionsData.ssh.configs?.filter(c => !c.ok).length }} issue(s) detected</template>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button v-if="!permissionsData.ssh.ok" @click="fixPermissions('ssh')" class="btn-warning btn-sm" :disabled="permissionsFixing.ssh">
              <span v-if="permissionsFixing.ssh" class="spinner"></span>
              <span v-else class="material-symbols-rounded text-lg">build</span>
              Fix
            </button>
            <button @click="fetchPermissions('ssh')" class="btn-secondary btn-sm" :disabled="permissionsLoading.ssh"><span class="material-symbols-rounded text-lg">refresh</span></button>
          </div>
        </div>
        <details v-if="!permissionsData.ssh.ok" class="mt-3">
          <summary class="text-sm text-surface-500 cursor-pointer">View details</summary>
          <div class="mt-2 text-sm space-y-2">
            <div v-for="config in permissionsData.ssh.configs" :key="config.path" class="flex items-start gap-2 p-2 rounded bg-surface-50 dark:bg-surface-800">
              <span :class="['material-symbols-rounded text-sm mt-0.5', config.ok ? 'text-green-500' : 'text-red-500']">{{ config.ok ? 'check' : 'close' }}</span>
              <div class="flex-1 min-w-0">
                <code class="text-xs break-all">{{ config.path }}</code>
                <div v-if="config.issues?.length" class="mt-1 text-xs text-red-500"><p v-for="issue in config.issues" :key="issue">• {{ issue }}</p></div>
              </div>
            </div>
          </div>
        </details>
      </div>
      <div v-else-if="permissionsLoading.ssh" class="card p-4"><div class="flex items-center gap-3 text-surface-500"><span class="spinner"></span><span>Checking permissions...</span></div></div>

      <div v-if="sshLoading" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading SSH configuration...</span>
        </div>
      </div>

      <!-- Raw Config Editor -->
      <div v-else-if="sshRawMode" class="card p-6">
        <ConfigEditor 
          v-model="sshRawConfig" 
          height="500px" 
          zen-title="SSH Configuration - sshd_config"
          service="ssh"
          @save="saveSshSettings"
          @open-guide="openGuide('ssh')"
        />
        <div class="flex justify-end gap-3 mt-4">
          <button @click="sshRawMode = false" class="btn-secondary">Cancel</button>
          <button @click="saveSshSettings" class="btn-primary" :disabled="sshSaving">
            <span v-if="sshSaving" class="spinner"></span>
            Save & Restart
          </button>
        </div>
      </div>

      <!-- Table-based Settings Editor with Sections -->
      <div v-else class="card overflow-hidden">
        <div v-for="section in sshSections" :key="section.id">
          <div class="px-6 py-3 bg-surface-50 dark:bg-surface-800/50 border-b border-surface-200 dark:border-surface-700">
            <h4 class="font-medium flex items-center gap-2">
              <span class="material-symbols-rounded text-lg text-surface-400">{{ section.icon }}</span>
              {{ section.label }}
            </h4>
          </div>
          <table class="w-full">
            <tbody>
              <tr v-for="setting in getSshSettingsBySection(section.id)" :key="setting.key" class="border-b border-surface-100 dark:border-surface-800 hover:bg-surface-50 dark:hover:bg-surface-800/30">
                <td class="px-6 py-4 w-1/3">
                  <div class="font-medium">{{ setting.label }}</div>
                  <div class="text-xs text-surface-500 mt-0.5">{{ setting.description }}</div>
                </td>
                <td class="px-6 py-4">
                  <template v-if="setting.type === 'toggle'">
                    <div class="flex items-center gap-3">
                      <label class="relative inline-flex items-center cursor-pointer" :class="{ 'pointer-events-none opacity-60': !sshEditMode }">
                        <input type="checkbox" v-model="sshSettings[setting.key]" :true-value="'yes'" :false-value="'no'" :disabled="!sshEditMode" class="sr-only peer"/>
                        <div class="w-11 h-6 bg-surface-300 dark:bg-surface-600 rounded-full peer peer-checked:bg-green-500 transition-colors"></div>
                        <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow peer-checked:translate-x-5 transition-transform"></div>
                      </label>
                      <span :class="['text-sm font-medium', sshSettings[setting.key] === 'yes' ? 'text-green-500' : 'text-surface-400']">
                        {{ sshSettings[setting.key] === 'yes' ? 'Enabled' : 'Disabled' }}
                      </span>
                    </div>
                  </template>
                  <template v-else-if="setting.type === 'select'">
                    <select v-if="sshEditMode" v-model="sshSettings[setting.key]" class="input max-w-md">
                      <option v-for="opt in setting.options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                    <span v-else class="font-mono text-sm">{{ sshSettings[setting.key] || '-' }}</span>
                  </template>
                  <template v-else>
                    <input v-if="sshEditMode" v-model="sshSettings[setting.key]" :type="setting.type || 'text'" :placeholder="setting.placeholder" class="input max-w-md"/>
                    <span v-else class="font-mono text-sm">{{ sshSettings[setting.key] || '-' }}</span>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <!-- Save button when in edit mode -->
        <div v-if="sshEditMode" class="px-6 py-4 bg-surface-50 dark:bg-surface-800/50 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-3">
          <button @click="cancelSshEdit" class="btn-secondary">Cancel</button>
          <button @click="saveSshSettings" class="btn-primary" :disabled="sshSaving || !sshHasChanges">
            <span v-if="sshSaving" class="spinner"></span>
            Save Changes
          </button>
        </div>
      </div>
    </div>

    <!-- Swap Tab -->
    <div v-if="activeTab === 'swap'" class="space-y-6">
      <div v-if="swapLoading" class="card p-12"><div class="flex items-center justify-center gap-3 text-surface-500"><span class="spinner"></span><span>Loading swap information...</span></div></div>
      <template v-else-if="swapInfo">
        <div class="card p-6">
          <div class="flex items-center justify-between flex-wrap gap-4 mb-6">
            <h3 class="text-lg font-semibold flex items-center gap-2"><span class="material-symbols-rounded text-purple-500">swap_horiz</span>Swap Memory</h3>
            <button v-if="!swapInfo.has_swap" @click="showCreateSwapModal = true" class="btn-primary"><span class="material-symbols-rounded">add</span>Create Swap</button>
          </div>
          <template v-if="swapInfo.has_swap">
            <div class="space-y-4 mb-6">
              <div class="flex justify-between text-sm"><span>Used: {{ swapInfo.used_human }}</span><span>Total: {{ swapInfo.total_human }}</span></div>
              <div class="w-full bg-surface-200 dark:bg-surface-700 rounded-full h-4"><div class="bg-purple-500 h-4 rounded-full transition-all" :style="{ width: swapInfo.percent_used + '%' }"/></div>
            </div>
            <div v-if="swapInfo.swap_files?.length" class="mb-6">
              <h4 class="text-sm font-medium mb-3">Swap Files</h4>
              <div class="space-y-2"><div v-for="file in swapInfo.swap_files" :key="file.path" class="flex justify-between items-center p-3 bg-surface-50 dark:bg-surface-800/50 rounded-xl"><div class="font-mono text-sm">{{ file.path }}</div><div class="text-sm text-surface-500">{{ formatBytes(file.size) }}</div></div></div>
            </div>
            <div><h4 class="text-sm font-medium mb-3">Swappiness</h4><div class="flex items-center gap-4"><input type="range" min="0" max="100" :value="swapInfo.swappiness" @change="updateSwappiness(parseInt($event.target.value))" class="w-64"/><span class="font-mono text-lg">{{ swapInfo.swappiness }}</span></div></div>
          </template>
          <div v-else class="text-center py-8"><span class="material-symbols-rounded text-4xl text-surface-300 mb-3 block">memory</span><p class="text-surface-500">No swap memory configured</p></div>
        </div>
      </template>
    </div>

    <!-- MOTD Tab -->
    <div v-if="activeTab === 'motd'" class="space-y-6">
      <div v-if="motdLoading" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading MOTD...</span>
        </div>
      </div>
      <template v-else-if="motdData">
        <!-- Current Output Preview -->
        <div class="card p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-cyan-500">preview</span>
              Current MOTD Output
            </h3>
            <div class="flex gap-2">
              <span v-if="motdData.has_dynamic" class="px-3 py-1 text-xs bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 rounded-full">
                Dynamic (Scripts)
              </span>
              <span v-else class="px-3 py-1 text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-full">
                Static
              </span>
            </div>
          </div>
          <pre class="bg-surface-900 text-green-400 p-4 rounded-xl font-mono text-sm overflow-x-auto whitespace-pre-wrap max-h-64 overflow-y-auto">{{ motdData.current_output || 'No MOTD content' }}</pre>
        </div>

        <!-- Edit Mode Toggle -->
        <div class="card p-6">
          <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-cyan-500">edit</span>
              Edit MOTD
            </h3>
            <div class="flex gap-2">
              <button 
                @click="switchToStaticMode" 
                :class="['btn-sm', motdEditMode === 'static' ? 'btn-primary' : 'btn-secondary']"
              >
                Static MOTD
              </button>
              <button 
                v-if="motdData.has_dynamic"
                @click="motdEditMode = 'scripts'" 
                :class="['btn-sm', motdEditMode === 'scripts' ? 'btn-primary' : 'btn-secondary']"
              >
                Dynamic Scripts
              </button>
            </div>
          </div>

          <!-- Static MOTD Editor -->
          <div v-if="motdEditMode === 'static'">
            <label class="block text-sm font-medium mb-2">MOTD Content <span class="text-xs text-surface-500 font-normal">(saved as executable script with colors & variables)</span></label>
            
            <!-- Dynamic Variables Toolbar -->
            <div class="mb-3 p-3 bg-surface-50 dark:bg-surface-800/50 rounded-xl">
              <p class="text-xs text-surface-500 mb-2 flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">auto_awesome</span>
                Insert Dynamic Variables (click to add):
              </p>
              <div class="flex flex-wrap gap-2">
                <button 
                  v-for="variable in motdVariables" 
                  :key="variable.code"
                  @click="insertMotdVariable(variable.code)"
                  class="inline-flex items-center gap-1 px-2 py-1 text-xs bg-surface-200 dark:bg-surface-700 hover:bg-cyan-100 dark:hover:bg-cyan-900/30 hover:text-cyan-700 dark:hover:text-cyan-300 rounded-lg transition-colors"
                  :title="variable.code"
                >
                  <span class="material-symbols-rounded text-sm">{{ variable.icon }}</span>
                  {{ variable.label }}
                </button>
              </div>
              
              <!-- Color Controls -->
              <div class="mt-3 pt-3 border-t border-surface-200 dark:border-surface-700">
                <p class="text-xs text-surface-500 mb-2 flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm">palette</span>
                  Text Colors:
                </p>
                <div class="flex flex-wrap items-center gap-2">
                  <!-- Color Selector -->
                  <div class="flex gap-1">
                    <button
                      v-for="col in motdColors"
                      :key="col.label"
                      @click="selectedColor = col"
                      class="w-6 h-6 rounded-full border-2 transition-all"
                      :class="selectedColor.label === col.label ? 'border-white scale-110 shadow-lg' : 'border-transparent opacity-70 hover:opacity-100'"
                      :style="{ backgroundColor: col.color }"
                      :title="col.label"
                    ></button>
                  </div>
                  
                  <!-- Color Start/End Buttons -->
                  <div class="flex gap-2 ml-2">
                    <button 
                      @click="insertColorStart"
                      class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded-lg transition-colors"
                      :style="{ backgroundColor: selectedColor.color + '20', color: selectedColor.color, borderColor: selectedColor.color }"
                      style="border-width: 1px;"
                    >
                      <span class="material-symbols-rounded text-sm">play_arrow</span>
                      Color Start
                    </button>
                    <button 
                      @click="insertColorEnd"
                      class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium bg-surface-200 dark:bg-surface-600 rounded-lg transition-colors hover:bg-surface-300 dark:hover:bg-surface-500"
                    >
                      <span class="material-symbols-rounded text-sm">stop</span>
                      Color End
                    </button>
                  </div>
                  
                  <!-- Preview -->
                  <span class="text-xs opacity-50 ml-2">
                    Selected: <span :style="{ color: selectedColor.color }">{{ selectedColor.label }}</span>
                  </span>
                </div>
              </div>
              
              <!-- Dynamic Blocks to Insert -->
              <div class="mt-3 pt-3 border-t border-surface-200 dark:border-surface-700">
                <p class="text-xs text-surface-500 mb-2 flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm">code_blocks</span>
                  Insert Dynamic Blocks (click to add at cursor):
                </p>
                <div class="flex flex-wrap gap-2">
                  <button 
                    v-for="block in dynamicBlocks"
                    :key="block.label"
                    @click="insertDynamicBlock(block.code)"
                    class="inline-flex items-center gap-2 px-3 py-2 text-sm bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-200 dark:hover:bg-emerald-900/50 rounded-lg transition-colors"
                    :title="block.description"
                  >
                    <span class="material-symbols-rounded text-base">{{ block.icon }}</span>
                    {{ block.label }}
                  </button>
                </div>
              </div>
            </div>
            
            <textarea 
              ref="motdStaticTextarea"
              v-model="motdContent" 
              rows="15"
              class="w-full bg-surface-900 text-green-400 p-4 rounded-xl font-mono text-sm resize-y mb-4"
              placeholder="Enter MOTD content... Click variables above to insert dynamic content."
            ></textarea>
            <div class="flex justify-end gap-3">
              <button @click="fetchMotd" class="btn-secondary" :disabled="motdSaving">
                <span class="material-symbols-rounded">refresh</span>
                Reset
              </button>
              <button @click="saveMotd" class="btn-primary" :disabled="motdSaving">
                <span v-if="motdSaving" class="spinner"></span>
                <span class="material-symbols-rounded" v-else>install_desktop</span>
                Install MOTD Script
              </button>
            </div>
          </div>

          <!-- Dynamic Scripts Editor -->
          <div v-if="motdEditMode === 'scripts' && motdData.scripts?.length" class="space-y-4">
            <!-- Disable CyberPanel button -->
            <div v-if="motdData.scripts.some(s => s.name === '00-cyberpanel')" class="flex justify-end">
              <button 
                @click="disableCyberpanelMotd" 
                class="btn-sm bg-amber-500/10 text-amber-600 hover:bg-amber-500/20 border border-amber-500/30"
                :disabled="motdSaving"
              >
                <span class="material-symbols-rounded">block</span>
                Disable CyberPanel MOTD
              </button>
            </div>

            <!-- Scripts List -->
            <div class="space-y-3">
              <div 
                v-for="script in motdData.scripts" 
                :key="script.name"
                class="border border-surface-200 dark:border-surface-700 rounded-xl overflow-hidden"
              >
                <!-- Script Header -->
                <div 
                  @click="toggleScript(script.name)"
                  class="flex items-center justify-between p-4 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
                >
                  <div class="flex items-center gap-3">
                    <span class="material-symbols-rounded text-surface-400 transition-transform" :class="{ 'rotate-90': expandedScripts[script.name] }">
                      chevron_right
                    </span>
                    <span class="font-mono text-sm">{{ script.name }}</span>
                    <span 
                      v-if="!script.executable" 
                      class="px-2 py-0.5 text-xs bg-surface-200 dark:bg-surface-700 text-surface-500 rounded"
                    >
                      disabled
                    </span>
                    <span 
                      v-else 
                      class="px-2 py-0.5 text-xs bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 rounded"
                    >
                      active
                    </span>
                  </div>
                  <span class="material-symbols-rounded text-surface-400">
                    {{ expandedScripts[script.name] ? 'expand_less' : 'expand_more' }}
                  </span>
                </div>

                <!-- Script Editor (Expanded) -->
                <div v-if="expandedScripts[script.name]" class="border-t border-surface-200 dark:border-surface-700 p-4 bg-surface-50 dark:bg-surface-800/30">
                  <!-- Quick Insert Variables -->
                  <div class="mb-3">
                    <p class="text-xs text-surface-500 mb-2">Quick insert:</p>
                    <div class="flex flex-wrap gap-1">
                      <button 
                        v-for="variable in motdVariables" 
                        :key="variable.code"
                        @click="insertScriptVariable(script.name, variable)"
                        class="inline-flex items-center gap-1 px-2 py-1 text-xs bg-surface-200 dark:bg-surface-600 hover:bg-cyan-100 dark:hover:bg-cyan-900/30 rounded transition-colors"
                        :title="variable.code"
                      >
                        <span class="material-symbols-rounded text-xs">{{ variable.icon }}</span>
                        {{ variable.label }}
                      </button>
                    </div>
                  </div>
                  
                  <textarea 
                    v-model="scriptContents[script.name]" 
                    rows="12"
                    class="w-full bg-surface-900 text-green-400 p-4 rounded-xl font-mono text-sm resize-y mb-3"
                    spellcheck="false"
                  ></textarea>
                  <div class="flex justify-end gap-3">
                    <button 
                      @click="scriptContents[script.name] = script.content" 
                      class="btn-secondary btn-sm"
                    >
                      <span class="material-symbols-rounded">undo</span>
                      Revert
                    </button>
                    <button 
                      @click="saveScript(script.name)" 
                      class="btn-primary btn-sm" 
                      :disabled="motdSaving"
                    >
                      <span v-if="motdSaving" class="spinner"></span>
                      <span class="material-symbols-rounded" v-else>save</span>
                      Save {{ script.name }}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Info -->
        <div class="card p-4 bg-surface-50 dark:bg-surface-800/50">
          <div class="flex items-start gap-3">
            <span class="material-symbols-rounded text-blue-500">info</span>
            <div class="text-sm text-surface-600 dark:text-surface-400">
              <p class="font-medium mb-1">About MOTD</p>
              <p class="mb-2">The Message of the Day (MOTD) is displayed when users log in via SSH. Static MOTD is stored at <code class="px-1.5 py-0.5 bg-surface-200 dark:bg-surface-700 rounded">{{ motdData.static_path }}</code>. Dynamic scripts are stored in <code class="px-1.5 py-0.5 bg-surface-200 dark:bg-surface-700 rounded">{{ motdData.scripts_path }}</code> and run in order.</p>
              <p class="font-medium mb-1">Dynamic Variables</p>
              <p>Use shell command substitution <code class="px-1.5 py-0.5 bg-surface-200 dark:bg-surface-700 rounded">$(command)</code> in static MOTD to display dynamic content. For scripts, use standard bash commands with <code class="px-1.5 py-0.5 bg-surface-200 dark:bg-surface-700 rounded">echo</code>. Scripts must start with <code class="px-1.5 py-0.5 bg-surface-200 dark:bg-surface-700 rounded">#!/bin/bash</code>.</p>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- Templates Tab -->
    <div v-if="activeTab === 'templates'" class="space-y-6">
      <div v-if="templatesLoading && !templatesData" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading templates...</span>
        </div>
      </div>
      <template v-else-if="templatesData">
        <!-- Template Editor Modal -->
        <Modal :show="showTemplateEditor" :title="`Edit Template: ${selectedTemplate?.name || ''}`" @close="showTemplateEditor = false" size="xl">
          <div class="space-y-4">
            <p class="text-sm text-surface-500">{{ selectedTemplate?.description }}</p>
            
            <!-- Loading State -->
            <div v-if="templatesLoading" class="flex items-center justify-center py-12">
              <span class="spinner"></span>
              <span class="ml-3 text-surface-500">Loading template...</span>
            </div>

            <template v-else>
              <!-- Action Bar -->
              <div class="flex items-center justify-between gap-4">
                <div class="flex gap-2">
                  <!-- File Upload Button -->
                  <label class="btn-secondary btn-sm cursor-pointer">
                    <span class="material-symbols-rounded">upload_file</span>
                    Upload HTML
                    <input 
                      type="file" 
                      accept=".html,.htm,text/html" 
                      @change="handleTemplateFileSelect"
                      class="hidden"
                    />
                  </label>
                </div>
                <button 
                  @click="templatePreview = !templatePreview" 
                  :class="['btn-sm', templatePreview ? 'btn-primary' : 'btn-secondary']"
                >
                  <span class="material-symbols-rounded">{{ templatePreview ? 'code' : 'preview' }}</span>
                  {{ templatePreview ? 'Edit Code' : 'Preview' }}
                </button>
              </div>

              <!-- Drag & Drop Zone + Editor -->
              <div 
                v-if="!templatePreview"
                @dragover="handleTemplateDragOver"
                @dragleave="handleTemplateDragLeave"
                @drop="handleTemplateDrop"
                :class="[
                  'relative rounded-xl transition-all',
                  templateDragOver ? 'ring-2 ring-purple-500 ring-offset-2' : ''
                ]"
              >
                <!-- Drag overlay -->
                <div 
                  v-if="templateDragOver" 
                  class="absolute inset-0 bg-purple-500/10 border-2 border-dashed border-purple-500 rounded-xl flex items-center justify-center z-10"
                >
                  <div class="text-center">
                    <span class="material-symbols-rounded text-4xl text-purple-500 mb-2 block">upload_file</span>
                    <p class="text-purple-600 font-medium">Drop HTML file here</p>
                  </div>
                </div>
                
                <textarea 
                  v-model="templateContent" 
                  rows="20"
                  class="w-full bg-surface-900 text-surface-100 p-4 rounded-xl font-mono text-sm resize-y"
                  spellcheck="false"
                  placeholder="Paste or type HTML code here, or drag & drop an HTML file..."
                ></textarea>
              </div>

              <!-- Preview -->
              <div v-if="templatePreview" class="border border-surface-200 dark:border-surface-700 rounded-xl overflow-hidden">
                <iframe 
                  :srcdoc="templateContent" 
                  class="w-full h-96 bg-white"
                  sandbox="allow-same-origin"
                ></iframe>
              </div>

              <!-- Upload Button & Hint -->
              <div class="flex items-center justify-between">
                <p class="text-xs text-surface-400 flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm">info</span>
                  Drag & drop an HTML file or paste code directly.
                </p>
                <label class="btn-sm btn-secondary cursor-pointer">
                  <span class="material-symbols-rounded">upload_file</span>
                  Upload HTML
                  <input 
                    type="file" 
                    accept=".html,.htm,text/html" 
                    @change="handleTemplateFileSelect"
                    class="hidden"
                  />
                </label>
              </div>
            </template>
          </div>
          <template #footer>
            <div class="flex justify-between">
              <button @click="resetTemplate" class="btn-secondary" :disabled="templatesSaving || templatesLoading">
                <span class="material-symbols-rounded">restart_alt</span>
                Reset to Default
              </button>
              <div class="flex gap-3">
                <button @click="showTemplateEditor = false" class="btn-secondary">Cancel</button>
                <button @click="saveTemplate" class="btn-primary" :disabled="templatesSaving || templatesLoading || !templateContent">
                  <span v-if="templatesSaving" class="spinner"></span>
                  <span class="material-symbols-rounded" v-else>save</span>
                  Save Template
                </button>
              </div>
            </div>
          </template>
        </Modal>

        <!-- Deploy Template Modal -->
        <Modal :show="showDeployModal" :title="`Deploy: ${deployingTemplate?.name || ''}`" @close="showDeployModal = false" size="lg">
          <div class="space-y-4">
            <!-- Pre-deploy options -->
            <div v-if="!deployResults" class="space-y-4">
              <p class="text-sm text-surface-500">
                Deploy the <strong>{{ deployingTemplate?.name }}</strong> template to sites.
              </p>
              
              <!-- Deploy Mode Selection -->
              <div class="flex gap-2">
                <button 
                  @click="deployMode = 'all'" 
                  :class="['flex-1 p-3 rounded-xl border transition-all text-left', deployMode === 'all' ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' : 'border-surface-200 dark:border-surface-700']"
                >
                  <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded" :class="deployMode === 'all' ? 'text-primary-500' : 'text-surface-400'">select_all</span>
                    <div>
                      <p class="font-medium text-sm">All Sites</p>
                      <p class="text-xs text-surface-500">Deploy to every site</p>
                    </div>
                  </div>
                </button>
                <button 
                  @click="deployMode = 'selected'" 
                  :class="['flex-1 p-3 rounded-xl border transition-all text-left', deployMode === 'selected' ? 'border-primary-500 bg-primary-50 dark:bg-primary-500/10' : 'border-surface-200 dark:border-surface-700']"
                >
                  <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded" :class="deployMode === 'selected' ? 'text-primary-500' : 'text-surface-400'">checklist</span>
                    <div>
                      <p class="font-medium text-sm">Select Sites</p>
                      <p class="text-xs text-surface-500">Choose specific sites</p>
                    </div>
                  </div>
                </button>
              </div>
              
              <!-- Skip Existing Option (only for "All" mode) -->
              <label v-if="deployMode === 'all'" class="flex items-center gap-3 p-3 bg-surface-50 dark:bg-surface-800 rounded-xl cursor-pointer">
                <div class="relative inline-flex items-center">
                  <input type="checkbox" v-model="deploySkipExisting" class="sr-only peer"/>
                  <div class="w-11 h-6 bg-surface-300 dark:bg-surface-600 rounded-full peer peer-checked:bg-green-500 transition-colors"></div>
                  <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow peer-checked:translate-x-5 transition-transform"></div>
                </div>
                <div>
                  <p class="text-sm font-medium">Skip sites with existing index.html</p>
                  <p class="text-xs text-surface-500">Only deploy to sites without an index file</p>
                </div>
              </label>
              
              <!-- Site Selection (for "Selected" mode) -->
              <div v-if="deployMode === 'selected'" class="space-y-2">
                <div class="flex items-center justify-between">
                  <p class="text-sm font-medium">Select Sites ({{ selectedDeploySites.length }}/{{ deploySites.length }})</p>
                  <div class="flex gap-2">
                    <button @click="selectAllSites" class="text-xs text-primary-500 hover:underline">Select All</button>
                    <button @click="deselectAllSites" class="text-xs text-surface-500 hover:underline">Clear</button>
                  </div>
                </div>
                
                <!-- Loading -->
                <div v-if="deploySitesLoading" class="flex items-center justify-center py-4">
                  <span class="spinner"></span>
                  <span class="ml-2 text-sm text-surface-500">Loading sites...</span>
                </div>
                
                <!-- Template Status Summary -->
                <div v-else-if="deploySites.filter(s => s.has_template_backup).length > 0" class="p-3 mb-3 bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 rounded-xl">
                  <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                      <span class="material-symbols-rounded text-green-500">check_circle</span>
                      <p class="text-sm text-green-700 dark:text-green-300">
                        <strong>{{ deploySites.filter(s => s.has_template_backup).length }}</strong> site(s) already have a template applied and can be reverted.
                      </p>
                    </div>
                    <button 
                      @click="showBulkRevertModal = true"
                      class="btn-sm rounded-full bg-green-500 hover:bg-green-600 text-white flex items-center gap-1"
                    >
                      <span class="material-symbols-rounded text-sm">restore</span>
                      Revert All
                    </button>
                  </div>
                </div>
                
                <!-- Sites List -->
                <div v-else class="max-h-48 overflow-y-auto border border-surface-200 dark:border-surface-700 rounded-xl">
                  <div 
                    v-for="site in deploySites" 
                    :key="site.domain"
                    class="flex items-center gap-3 p-3 hover:bg-surface-50 dark:hover:bg-surface-800 border-b border-surface-100 dark:border-surface-800 last:border-b-0"
                    :class="site.has_template_backup ? 'bg-green-50/50 dark:bg-green-500/5' : ''"
                  >
                    <!-- Toggle Switch -->
                    <button
                      type="button"
                      @click="toggleSiteSelection(site.domain)"
                      :class="[
                        'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2',
                        selectedDeploySites.includes(site.domain) ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                      ]"
                    >
                      <span
                        :class="[
                          'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                          selectedDeploySites.includes(site.domain) ? 'translate-x-5' : 'translate-x-0'
                        ]"
                      />
                    </button>
                    <div class="flex-1 min-w-0 cursor-pointer" @click="toggleSiteSelection(site.domain)">
                      <p class="text-sm font-medium truncate">{{ site.domain }}</p>
                      <p v-if="site.has_template_backup" class="text-xs text-green-600 dark:text-green-400 flex items-center gap-1">
                        <span 
                          class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium"
                          :class="getTemplateTypeBadgeClass(site.template_type)"
                        >
                          {{ getTemplateTypeLabel(site.template_type) }}
                        </span>
                        <span v-if="site.deployed_at">{{ formatRelativeTime(site.deployed_at) }}</span>
                      </p>
                    </div>
                    <!-- Individual Revert Button -->
                    <button 
                      v-if="site.has_template_backup"
                      @click.stop="revertSingleSite(site.domain)"
                      :disabled="revertingSite === site.domain"
                      class="btn-sm rounded-full bg-green-500 hover:bg-green-600 text-white flex items-center gap-1"
                      title="Revert this site"
                    >
                      <span v-if="revertingSite === site.domain" class="spinner"></span>
                      <span class="material-symbols-rounded text-sm" v-else>restore</span>
                      <span class="hidden sm:inline">Revert</span>
                    </button>
                    <span v-else-if="site.has_index" class="text-xs px-2 py-0.5 rounded bg-surface-100 dark:bg-surface-700 text-surface-500">
                      has index
                    </span>
                  </div>
                  <div v-if="!deploySites.length" class="p-4 text-center text-sm text-surface-500">
                    No sites found
                  </div>
                </div>
              </div>
              
              <!-- Warning -->
              <div class="p-3 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl">
                <div class="flex items-start gap-2">
                  <span class="material-symbols-rounded text-amber-500 text-lg">warning</span>
                  <p class="text-sm text-amber-700 dark:text-amber-300">
                    This will overwrite the index.html file. A backup will be created.
                  </p>
                </div>
              </div>
            </div>
            
            <!-- Results -->
            <div v-if="deployResults" class="space-y-3">
              <div class="grid grid-cols-3 gap-3">
                <div class="p-3 bg-green-50 dark:bg-green-500/10 rounded-xl text-center">
                  <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ deployResults.deployed?.length || 0 }}</p>
                  <p class="text-xs text-green-600 dark:text-green-400">Deployed</p>
                </div>
                <div class="p-3 bg-amber-50 dark:bg-amber-500/10 rounded-xl text-center">
                  <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ deployResults.skipped?.length || 0 }}</p>
                  <p class="text-xs text-amber-600 dark:text-amber-400">Skipped</p>
                </div>
                <div class="p-3 bg-red-50 dark:bg-red-500/10 rounded-xl text-center">
                  <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ deployResults.failed?.length || 0 }}</p>
                  <p class="text-xs text-red-600 dark:text-red-400">Failed</p>
                </div>
              </div>
              
              <div v-if="deployResults.deployed?.length" class="max-h-32 overflow-y-auto">
                <p class="text-xs font-medium text-surface-500 mb-1">Deployed to:</p>
                <div class="flex flex-wrap gap-1">
                  <span v-for="site in deployResults.deployed" :key="site" class="px-2 py-0.5 bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300 rounded text-xs">
                    {{ site }}
                  </span>
                </div>
              </div>
            </div>
            
            <!-- Actions -->
            <div class="flex justify-end gap-3 pt-4 border-t border-surface-200 dark:border-surface-700">
              <button @click="showDeployModal = false" class="btn-secondary rounded-full">
                {{ deployResults ? 'Close' : 'Cancel' }}
              </button>
              <button 
                v-if="!deployResults" 
                @click="deployToSites" 
                class="btn-primary rounded-full" 
                :disabled="deploying || (deployMode === 'selected' && !selectedDeploySites.length)"
              >
                <span v-if="deploying" class="spinner"></span>
                <span class="material-symbols-rounded" v-else>rocket_launch</span>
                {{ deployMode === 'all' ? 'Deploy to All Sites' : `Deploy to ${selectedDeploySites.length} Site(s)` }}
              </button>
            </div>
          </div>
        </Modal>

        <!-- Bulk Revert Modal -->
        <Modal :show="showBulkRevertModal" title="Revert Templates" @close="showBulkRevertModal = false" size="lg">
          <div class="space-y-4">
            <p class="text-sm text-surface-500">
              Restore the original <code class="px-1.5 py-0.5 bg-surface-200 dark:bg-surface-700 rounded">index.html</code> 
              for sites that have a template applied. You can revert individual sites or all at once.
            </p>
            
            <!-- Sites List -->
            <div class="border border-surface-200 dark:border-surface-700 rounded-xl overflow-hidden">
              <div class="bg-surface-50 dark:bg-surface-800/50 px-4 py-2 border-b border-surface-200 dark:border-surface-700">
                <p class="text-sm font-medium">
                  <strong>{{ deploySites.filter(s => s.has_template_backup).length }}</strong> site(s) with active templates
                </p>
              </div>
              <div class="max-h-64 overflow-y-auto divide-y divide-surface-100 dark:divide-surface-800">
                <div 
                  v-for="site in deploySites.filter(s => s.has_template_backup)" 
                  :key="site.domain"
                  class="flex items-center justify-between gap-3 px-4 py-3 hover:bg-surface-50 dark:hover:bg-surface-800/50"
                >
                  <div class="flex items-center gap-3 min-w-0">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                      :class="getTemplateTypeBadgeClass(site.template_type)">
                      <span class="material-symbols-rounded text-sm">
                        {{ site.template_type === 'site_maintenance' ? 'build' : site.template_type === 'site_coming_soon' ? 'schedule' : 'web' }}
                      </span>
                    </div>
                    <div class="min-w-0">
                      <p class="text-sm font-medium truncate">{{ site.domain }}</p>
                      <p class="text-xs text-surface-500">{{ getTemplateTypeLabel(site.template_type) }}</p>
                    </div>
                  </div>
                  <button 
                    @click="revertSingleSite(site.domain)"
                    :disabled="revertingSite === site.domain"
                    class="btn-sm rounded-full bg-green-500 hover:bg-green-600 text-white flex items-center gap-1 shrink-0"
                  >
                    <span v-if="revertingSite === site.domain" class="spinner"></span>
                    <span class="material-symbols-rounded text-sm" v-else>restore</span>
                    Revert
                  </button>
                </div>
                <div v-if="!deploySites.filter(s => s.has_template_backup).length" class="px-4 py-8 text-center text-surface-400">
                  <span class="material-symbols-rounded text-3xl mb-2 block">check_circle</span>
                  No sites have templates applied
                </div>
              </div>
            </div>
            
            <div class="flex justify-between items-center pt-4 border-t border-surface-200 dark:border-surface-700">
              <p class="text-xs text-surface-500">
                Reverting restores the original file and removes the template
              </p>
              <div class="flex gap-3">
                <button @click="showBulkRevertModal = false" class="btn-secondary rounded-full">Close</button>
                <button 
                  v-if="deploySites.filter(s => s.has_template_backup).length > 1"
                  @click="bulkRevertTemplates" 
                  class="btn-primary rounded-full bg-green-500 hover:bg-green-600"
                  :disabled="bulkReverting || !deploySites.filter(s => s.has_template_backup).length"
                >
                  <span v-if="bulkReverting" class="spinner"></span>
                  <span class="material-symbols-rounded" v-else>restore</span>
                  Revert All ({{ deploySites.filter(s => s.has_template_backup).length }})
                </button>
              </div>
            </div>
          </div>
        </Modal>

        <!-- Error Pages Section -->
        <div class="card p-6">
          <h3 class="text-lg font-semibold flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-red-500">error</span>
            Error Page Templates
          </h3>
          <p class="text-sm text-surface-500 mb-6">
            Custom HTML templates for HTTP error pages. These will be used by OpenLiteSpeed and new sites.
          </p>
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div 
              v-for="template in errorTemplates" 
              :key="template.id"
              @click="editTemplate(template)"
              class="p-4 border border-surface-200 dark:border-surface-700 rounded-xl cursor-pointer hover:border-purple-500 dark:hover:border-purple-500 transition-all group"
            >
              <div class="flex items-center justify-between mb-2">
                <span class="text-2xl font-bold text-surface-300 group-hover:text-purple-500 transition-colors">
                  {{ template.id.replace('error_', '') }}
                </span>
                <span 
                  :class="[
                    'w-2 h-2 rounded-full',
                    template.exists ? 'bg-green-500' : 'bg-surface-300'
                  ]"
                  :title="template.exists ? 'Customized' : 'Using default'"
                ></span>
              </div>
              <p class="text-sm font-medium">{{ template.name }}</p>
              <p class="text-xs text-surface-500">{{ template.description }}</p>
            </div>
          </div>
        </div>

        <!-- Site Templates Section -->
        <div class="card p-6">
          <h3 class="text-lg font-semibold flex items-center gap-2 mb-4">
            <span class="material-symbols-rounded text-blue-500">web</span>
            Site Templates
          </h3>
          <p class="text-sm text-surface-500 mb-6">
            Default pages for new sites and maintenance modes.
          </p>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div 
              v-for="template in siteTemplates" 
              :key="template.id"
              class="p-4 border border-surface-200 dark:border-surface-700 rounded-xl transition-all group"
            >
              <div class="flex items-center justify-between mb-2">
                <span class="material-symbols-rounded text-xl text-surface-400 group-hover:text-blue-500 transition-colors">
                  {{ template.id === 'site_placeholder' ? 'rocket_launch' : template.id === 'site_coming_soon' ? 'schedule' : 'construction' }}
                </span>
                <span 
                  :class="[
                    'w-2 h-2 rounded-full',
                    template.exists ? 'bg-green-500' : 'bg-surface-300'
                  ]"
                  :title="template.exists ? 'Customized' : 'Using default'"
                ></span>
              </div>
              <p class="text-sm font-medium">{{ template.name }}</p>
              <p class="text-xs text-surface-500 mb-3">{{ template.description }}</p>
              <p v-if="template.modified" class="text-xs text-surface-400 mb-3">
                Modified: {{ template.modified }}
              </p>
              <div class="flex gap-2">
                <button @click="editTemplate(template)" class="btn-secondary btn-sm flex-1">
                  <span class="material-symbols-rounded">edit</span>
                  Edit
                </button>
                <button @click.stop="openDeployModal(template)" class="btn-primary btn-sm flex-1">
                  <span class="material-symbols-rounded">rocket_launch</span>
                  Deploy All
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Info -->
        <div class="card p-4 bg-surface-50 dark:bg-surface-800/50">
          <div class="flex items-start gap-3">
            <span class="material-symbols-rounded text-blue-500">info</span>
            <div class="text-sm text-surface-600 dark:text-surface-400">
              <p class="font-medium mb-1">About Templates</p>
              <p>Templates are stored at <code class="px-1.5 py-0.5 bg-surface-200 dark:bg-surface-700 rounded">{{ templatesData.path }}</code>. Error page templates are also deployed to OpenLiteSpeed's docs directory. The placeholder template is used as the default page for new sites.</p>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- OpenLiteSpeed Tab -->
    <div v-if="activeTab === 'ols'" class="space-y-6">
      <!-- Header with config path -->
      <div class="card p-6">
        <div class="flex items-center justify-between flex-wrap gap-4 mb-4">
          <div>
            <h3 class="text-lg font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-amber-500">bolt</span>
              OpenLiteSpeed Configuration
            </h3>
            <div class="flex items-center gap-2 mt-2">
              <code class="text-sm bg-surface-100 dark:bg-surface-800 px-3 py-1 rounded-lg font-mono text-surface-600 dark:text-surface-400">{{ olsConfigPath }}</code>
              <button @click="copyToClipboard(olsConfigPath)" class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-800 rounded-lg transition-colors">
                <span class="material-symbols-rounded text-lg text-surface-500">content_copy</span>
              </button>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <button @click="openOlsCalculator" class="btn-secondary" title="extprocessor Configuration Calculator">
              <span class="material-symbols-rounded">calculate</span>
              Calculator
            </button>
            <button @click="openGuide('ols')" class="btn-secondary" title="Best Practices Guide">
              <span class="material-symbols-rounded">lightbulb</span>
              Guide
            </button>
            <button @click="olsRawMode = !olsRawMode" :class="['btn-secondary', olsRawMode && 'bg-surface-200 dark:bg-surface-700']">
              <span class="material-symbols-rounded">code</span>
              Raw Edit
            </button>
            <button @click="olsEditMode = !olsEditMode; olsRawMode = false" :class="['btn-primary', olsEditMode && !olsRawMode && 'bg-primary-600']">
              <span class="material-symbols-rounded">edit</span>
              Edit Values
            </button>
          </div>
        </div>
        
        <!-- Status bar -->
        <div class="flex items-center justify-between flex-wrap gap-4 pt-4 border-t border-surface-200 dark:border-surface-700">
          <div class="flex items-center gap-6 flex-wrap">
            <div class="flex items-center gap-2">
              <span :class="['w-2 h-2 rounded-full', olsStatus?.running ? 'bg-green-500' : 'bg-red-500']"></span>
              <span class="text-sm font-medium">{{ olsStatus?.running ? 'Running' : 'Stopped' }}</span>
            </div>
            <div v-if="olsStatus?.version" class="text-sm text-surface-500">Version: <span class="font-mono">{{ olsStatus.version }}</span></div>
            <div v-if="olsStatus?.pid" class="text-sm text-surface-500">PID: <span class="font-mono">{{ olsStatus.pid }}</span></div>
          </div>
          <div class="flex items-center gap-2">
            <button @click="reloadOls" class="btn-secondary btn-sm" :disabled="olsSaving">
              <span class="material-symbols-rounded text-lg">sync</span>
              Reload
            </button>
            <button @click="restartOls" class="btn-secondary btn-sm" :disabled="olsSaving">
              <span class="material-symbols-rounded text-lg">refresh</span>
              Restart
            </button>
          </div>
        </div>
      </div>

      <!-- Config Permissions Card -->
      <div v-if="permissionsData.ols" class="card p-4" :class="permissionsData.ols.ok ? 'border-l-4 border-l-green-500' : 'border-l-4 border-l-red-500'">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span :class="['material-symbols-rounded text-xl', permissionsData.ols.ok ? 'text-green-500' : 'text-red-500']">
              {{ permissionsData.ols.ok ? 'check_circle' : 'error' }}
            </span>
            <div>
              <p class="font-medium">Config File Permissions</p>
              <p class="text-sm text-surface-500">
                <template v-if="permissionsData.ols.ok">All config files have correct permissions</template>
                <template v-else>
                  {{ permissionsData.ols.configs?.filter(c => !c.ok).length }} issue(s) detected
                </template>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button v-if="!permissionsData.ols.ok" @click="fixPermissions('ols')" class="btn-warning btn-sm" :disabled="permissionsFixing.ols">
              <span v-if="permissionsFixing.ols" class="spinner"></span>
              <span v-else class="material-symbols-rounded text-lg">build</span>
              Fix Permissions
            </button>
            <button @click="fetchPermissions('ols')" class="btn-secondary btn-sm" :disabled="permissionsLoading.ols">
              <span class="material-symbols-rounded text-lg">refresh</span>
            </button>
          </div>
        </div>
        <!-- Details expandable -->
        <details v-if="!permissionsData.ols.ok" class="mt-3">
          <summary class="text-sm text-surface-500 cursor-pointer hover:text-surface-700 dark:hover:text-surface-300">View details</summary>
          <div class="mt-2 text-sm space-y-2">
            <div v-for="config in permissionsData.ols.configs" :key="config.path" class="flex items-start gap-2 p-2 rounded bg-surface-50 dark:bg-surface-800">
              <span :class="['material-symbols-rounded text-sm mt-0.5', config.ok ? 'text-green-500' : 'text-red-500']">
                {{ config.ok ? 'check' : 'close' }}
              </span>
              <div class="flex-1 min-w-0">
                <code class="text-xs break-all">{{ config.path }}</code>
                <div v-if="config.issues?.length" class="mt-1 text-xs text-red-500">
                  <p v-for="issue in config.issues" :key="issue">• {{ issue }}</p>
                </div>
              </div>
            </div>
          </div>
        </details>
      </div>
      <div v-else-if="permissionsLoading.ols" class="card p-4">
        <div class="flex items-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Checking permissions...</span>
        </div>
      </div>

      <div v-if="olsLoading" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading OpenLiteSpeed configuration...</span>
        </div>
      </div>

      <!-- Raw Config Editor -->
      <div v-else-if="olsRawMode" class="card p-6">
        <ConfigEditor 
          v-model="olsRawConfig" 
          height="500px" 
          zen-title="OpenLiteSpeed Configuration - httpd_config.conf"
          service="ols"
          @save="saveOlsSettings"
          @open-guide="openGuide('ols')"
        />
        <div class="flex justify-end gap-3 mt-4">
          <button @click="olsRawMode = false" class="btn-secondary">Cancel</button>
          <button @click="saveOlsSettings" class="btn-primary" :disabled="olsSaving">
            <span v-if="olsSaving" class="spinner"></span>
            Save & Restart
          </button>
        </div>
      </div>

      <!-- Table-based Settings Editor -->
      <div v-else class="card overflow-hidden">
        <div v-for="section in olsSections" :key="section.id">
          <div class="px-6 py-3 bg-surface-50 dark:bg-surface-800/50 border-b border-surface-200 dark:border-surface-700">
            <h4 class="font-medium flex items-center gap-2">
              <span class="material-symbols-rounded text-lg text-surface-400">{{ section.icon }}</span>
              {{ section.label }}
            </h4>
          </div>
          <table class="w-full">
            <tbody>
              <tr v-for="def in getSettingsBySection(section.id)" :key="def.key" class="border-b border-surface-100 dark:border-surface-800 hover:bg-surface-50 dark:hover:bg-surface-800/30">
                <td class="px-6 py-4 w-1/3">
                  <div class="font-medium">{{ def.label }}</div>
                  <div class="text-xs text-surface-500 mt-0.5">{{ def.description }}</div>
                </td>
                <td class="px-6 py-4">
                  <template v-if="def.type === 'toggle'">
                    <div class="flex items-center gap-3">
                      <label class="relative inline-flex items-center cursor-pointer" :class="{ 'pointer-events-none opacity-60': !olsEditMode }">
                        <input type="checkbox" v-model="olsSettings[def.key]" :true-value="'1'" :false-value="'0'" :disabled="!olsEditMode" class="sr-only peer"/>
                        <div class="w-11 h-6 bg-surface-300 dark:bg-surface-600 rounded-full peer peer-checked:bg-green-500 transition-colors"></div>
                        <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow peer-checked:translate-x-5 transition-transform"></div>
                      </label>
                      <span :class="['text-sm font-medium', olsSettings[def.key] === '1' ? 'text-green-500' : 'text-surface-400']">
                        {{ olsSettings[def.key] === '1' ? 'Enabled' : 'Disabled' }}
                      </span>
                    </div>
                  </template>
                  <template v-else>
                    <input v-if="olsEditMode" v-model="olsSettings[def.key]" :placeholder="def.placeholder" class="input max-w-md"/>
                    <span v-else class="font-mono text-sm">{{ olsSettings[def.key] || '-' }}</span>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <!-- Save button when in edit mode -->
        <div v-if="olsEditMode" class="px-6 py-4 bg-surface-50 dark:bg-surface-800/50 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-3">
          <button @click="cancelOlsEdit" class="btn-secondary">Cancel</button>
          <button @click="saveOlsSettings" class="btn-primary" :disabled="olsSaving || !olsHasChanges">
            <span v-if="olsSaving" class="spinner"></span>
            Save Changes
          </button>
        </div>
      </div>
    </div>

    <!-- PHP Tab -->
    <div v-if="activeTab === 'php'" class="space-y-6">
      <!-- Header with config path -->
      <div class="card p-6">
        <div class="flex items-center justify-between flex-wrap gap-4 mb-4">
          <div>
            <h3 class="text-lg font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-indigo-500">code</span>
              PHP Configuration
            </h3>
            <div class="flex items-center gap-2 mt-2">
              <div class="relative">
                <select 
                  v-model="phpSelectedConfigFile" 
                  class="input pr-8 text-sm font-mono bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400 min-w-[400px]"
                >
                  <option v-for="file in phpConfigFiles" :key="file.path" :value="file.path">
                    {{ file.label }} - {{ file.description }}
                  </option>
                </select>
              </div>
              <button @click="copyToClipboard(phpConfigPath)" class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-800 rounded-lg transition-colors" title="Copy path">
                <span class="material-symbols-rounded text-lg text-surface-500">content_copy</span>
              </button>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <select v-model="selectedVersion" class="input w-40" :disabled="phpLoading">
              <option v-for="php in filteredPhpVersions" :key="php.version" :value="php.version">PHP {{ php.version }}</option>
            </select>
            <button @click="openGuide('php')" class="btn-secondary" title="Best Practices Guide">
              <span class="material-symbols-rounded">lightbulb</span>
              Guide
            </button>
            <button @click="phpRawMode = !phpRawMode" :class="['btn-secondary', phpRawMode && 'bg-surface-200 dark:bg-surface-700']">
              <span class="material-symbols-rounded">code</span>
              Raw Edit
            </button>
            <button @click="phpEditMode = !phpEditMode; phpRawMode = false" :class="['btn-primary', phpEditMode && !phpRawMode && 'bg-primary-600']">
              <span class="material-symbols-rounded">edit</span>
              Edit Values
            </button>
          </div>
        </div>
        
        <!-- Status bar -->
        <div class="flex items-center justify-between flex-wrap gap-4 pt-4 border-t border-surface-200 dark:border-surface-700">
          <div class="flex items-center gap-6 flex-wrap">
            <div class="flex items-center gap-2">
              <span class="w-2 h-2 rounded-full bg-green-500"></span>
              <span class="text-sm font-medium">PHP {{ selectedVersion }} Active</span>
            </div>
          </div>
          <button @click="restartPhp" class="btn-secondary btn-sm" :disabled="phpSaving || !selectedVersion">
            <span class="material-symbols-rounded text-lg">refresh</span>
            Restart PHP-FPM
          </button>
        </div>
      </div>

      <!-- Config Permissions Card -->
      <div v-if="permissionsData.php" class="card p-4" :class="permissionsData.php.ok ? 'border-l-4 border-l-green-500' : 'border-l-4 border-l-red-500'">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span :class="['material-symbols-rounded text-xl', permissionsData.php.ok ? 'text-green-500' : 'text-red-500']">
              {{ permissionsData.php.ok ? 'check_circle' : 'error' }}
            </span>
            <div>
              <p class="font-medium">Config File Permissions</p>
              <p class="text-sm text-surface-500">
                <template v-if="permissionsData.php.ok">All config files have correct permissions</template>
                <template v-else>{{ permissionsData.php.configs?.filter(c => !c.ok).length }} issue(s) detected</template>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button v-if="!permissionsData.php.ok" @click="fixPermissions('php')" class="btn-warning btn-sm" :disabled="permissionsFixing.php">
              <span v-if="permissionsFixing.php" class="spinner"></span>
              <span v-else class="material-symbols-rounded text-lg">build</span>
              Fix
            </button>
            <button @click="fetchPermissions('php')" class="btn-secondary btn-sm" :disabled="permissionsLoading.php">
              <span class="material-symbols-rounded text-lg">refresh</span>
            </button>
          </div>
        </div>
        <details v-if="!permissionsData.php.ok" class="mt-3">
          <summary class="text-sm text-surface-500 cursor-pointer">View details</summary>
          <div class="mt-2 text-sm space-y-2">
            <div v-for="config in permissionsData.php.configs" :key="config.path" class="flex items-start gap-2 p-2 rounded bg-surface-50 dark:bg-surface-800">
              <span :class="['material-symbols-rounded text-sm mt-0.5', config.ok ? 'text-green-500' : 'text-red-500']">{{ config.ok ? 'check' : 'close' }}</span>
              <div class="flex-1 min-w-0">
                <code class="text-xs break-all">{{ config.path }}</code>
                <div v-if="config.issues?.length" class="mt-1 text-xs text-red-500">
                  <p v-for="issue in config.issues" :key="issue">• {{ issue }}</p>
                </div>
              </div>
            </div>
          </div>
        </details>
      </div>
      <div v-else-if="permissionsLoading.php" class="card p-4">
        <div class="flex items-center gap-3 text-surface-500"><span class="spinner"></span><span>Checking permissions...</span></div>
      </div>

      <div v-if="phpLoading" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading PHP configuration...</span>
        </div>
      </div>

      <!-- Raw Config Editor -->
      <div v-else-if="phpRawMode" class="card p-6">
        <div v-if="phpRawLoading && !phpRawConfig" class="flex items-center justify-center py-12">
          <div class="flex items-center gap-3 text-surface-500">
            <span class="spinner"></span>
            <span>Loading {{ phpConfigFiles.find(f => f.path === phpSelectedConfigFile)?.label || 'config' }}...</span>
          </div>
        </div>
        <template v-else>
          <div class="relative">
            <ConfigEditor 
              v-model="phpRawConfig" 
              height="500px" 
              :zen-title="`${phpConfigFiles.find(f => f.path === phpSelectedConfigFile)?.label || 'PHP Configuration'}`"
              service="php"
              :config-files="phpConfigFiles"
              :selected-file="phpSelectedConfigFile"
              :loading="phpRawLoading"
              @save="savePhpRawConfig"
              @open-guide="openGuide('php')"
              @file-change="phpSelectedConfigFile = $event"
            />
            <div v-if="phpRawLoading" class="absolute top-2 right-2 flex items-center gap-2 bg-surface-800/90 px-3 py-1.5 rounded-lg text-xs text-surface-300">
              <span class="spinner-sm"></span>
              Loading...
            </div>
          </div>
          <div class="flex justify-end gap-3 mt-4">
            <button @click="phpRawMode = false" class="btn-secondary">Cancel</button>
            <button @click="savePhpRawConfig" class="btn-primary" :disabled="phpSaving || phpRawLoading">
              <span v-if="phpSaving" class="spinner"></span>
              Save & Restart
            </button>
          </div>
        </template>
      </div>

      <!-- Table-based Settings Editor -->
      <div v-else class="card overflow-hidden">
        <div v-for="section in phpSections" :key="section.id">
          <div class="px-6 py-3 bg-surface-50 dark:bg-surface-800/50 border-b border-surface-200 dark:border-surface-700">
            <h4 class="font-medium flex items-center gap-2">
              <span class="material-symbols-rounded text-lg text-surface-400">{{ section.icon }}</span>
              {{ section.label }}
            </h4>
          </div>
          <table class="w-full">
            <tbody>
              <tr v-for="def in getPhpSettingsBySection(section.id)" :key="def.key" class="border-b border-surface-100 dark:border-surface-800 hover:bg-surface-50 dark:hover:bg-surface-800/30">
                <td class="px-6 py-4 w-1/3">
                  <div class="font-medium">{{ def.label }}</div>
                  <div class="text-xs text-surface-500 mt-0.5">{{ def.description }}</div>
                </td>
                <td class="px-6 py-4">
                  <template v-if="def.type === 'status'">
                    <div class="flex items-center gap-2">
                      <span :class="['w-2 h-2 rounded-full', phpSettings[def.key] ? 'bg-green-500' : 'bg-surface-400']"></span>
                      <span :class="['text-sm font-medium', phpSettings[def.key] ? 'text-green-500' : 'text-surface-400']">
                        {{ phpSettings[def.key] ? 'Loaded' : 'Not Loaded' }}
                      </span>
                    </div>
                  </template>
                  <template v-else-if="def.type === 'toggle'">
                    <div class="flex items-center gap-3">
                      <button @click="phpSettings[def.key] = (phpSettings[def.key] === 'On' || phpSettings[def.key] === '1') ? (def.key.startsWith('opcache') ? '0' : 'Off') : (def.key.startsWith('opcache') ? '1' : 'On')" :disabled="!phpEditMode" :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors', (phpSettings[def.key] === 'On' || phpSettings[def.key] === '1') ? 'bg-green-500' : 'bg-surface-300 dark:bg-surface-600', !phpEditMode && 'opacity-60 cursor-not-allowed']">
                        <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition-transform', (phpSettings[def.key] === 'On' || phpSettings[def.key] === '1') ? 'translate-x-6' : 'translate-x-1']"/>
                      </button>
                      <span :class="['text-sm font-medium', (phpSettings[def.key] === 'On' || phpSettings[def.key] === '1') ? 'text-green-500' : 'text-surface-400']">
                        {{ (phpSettings[def.key] === 'On' || phpSettings[def.key] === '1') ? 'Enabled' : 'Disabled' }}
                      </span>
                    </div>
                  </template>
                  <template v-else-if="def.type === 'select'">
                    <select v-if="phpEditMode" v-model="phpSettings[def.key]" class="input max-w-md">
                      <option v-for="opt in def.options" :key="opt" :value="opt">{{ opt }}</option>
                    </select>
                    <span v-else class="font-mono text-sm">{{ phpSettings[def.key] || '-' }}</span>
                  </template>
                  <template v-else>
                    <input v-if="phpEditMode" v-model="phpSettings[def.key]" :placeholder="def.placeholder" class="input max-w-md"/>
                    <span v-else class="font-mono text-sm">{{ phpSettings[def.key] || '-' }}</span>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <!-- Save button when in edit mode -->
        <div v-if="phpEditMode" class="px-6 py-4 bg-surface-50 dark:bg-surface-800/50 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-3">
          <button @click="cancelPhpEdit" class="btn-secondary">Cancel</button>
          <button @click="savePhpSettings" class="btn-primary" :disabled="phpSaving || !phpHasChanges">
            <span v-if="phpSaving" class="spinner"></span>
            Save Changes
          </button>
        </div>
      </div>
    </div>

    <!-- MySQL Tab -->
    <div v-if="activeTab === 'mysql'" class="space-y-6">
      <!-- Header with config path -->
      <div class="card p-6">
        <div class="flex items-center justify-between flex-wrap gap-4 mb-4">
          <div>
            <h3 class="text-lg font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-orange-500">database</span>
              MySQL Configuration
            </h3>
            <div class="flex items-center gap-2 mt-2">
              <select 
                v-model="mysqlSelectedConfigFile" 
                class="input pr-8 text-sm font-mono bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400 min-w-[280px]"
              >
                <option v-for="file in mysqlConfigFiles" :key="file.path" :value="file.path">
                  {{ file.label }} - {{ file.description }}
                </option>
              </select>
              <button @click="copyToClipboard(mysqlSelectedConfigFile)" class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-800 rounded-lg transition-colors" title="Copy path">
                <span class="material-symbols-rounded text-lg text-surface-500">content_copy</span>
              </button>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <button @click="openGuide('mysql')" class="btn-secondary" title="Best Practices Guide">
              <span class="material-symbols-rounded">lightbulb</span>
              Guide
            </button>
            <button @click="mysqlRawMode = !mysqlRawMode" :class="['btn-secondary', mysqlRawMode && 'bg-surface-200 dark:bg-surface-700']">
              <span class="material-symbols-rounded">code</span>
              Raw Edit
            </button>
            <button @click="mysqlEditMode = !mysqlEditMode; mysqlRawMode = false" :class="['btn-primary', mysqlEditMode && !mysqlRawMode && 'bg-primary-600']">
              <span class="material-symbols-rounded">edit</span>
              Edit Values
            </button>
          </div>
        </div>
        
        <!-- Status bar -->
        <div class="flex items-center justify-between flex-wrap gap-4 pt-4 border-t border-surface-200 dark:border-surface-700">
          <div class="flex items-center gap-6 flex-wrap">
            <div class="flex items-center gap-2">
              <span :class="['w-2 h-2 rounded-full', mysqlStatus?.running ? 'bg-green-500' : 'bg-red-500']"></span>
              <span class="text-sm font-medium">{{ mysqlStatus?.running ? 'Running' : 'Stopped' }}</span>
            </div>
            <div v-if="mysqlStatus?.version" class="text-sm text-surface-500">Version: <span class="font-mono">{{ mysqlStatus.version }}</span></div>
          </div>
          <button @click="restartMysql" class="btn-secondary btn-sm" :disabled="mysqlSaving">
            <span class="material-symbols-rounded text-lg">refresh</span>
            Restart MySQL
          </button>
        </div>
      </div>

      <!-- Config Permissions Card -->
      <div v-if="permissionsData.mysql" class="card p-4" :class="permissionsData.mysql.ok ? 'border-l-4 border-l-green-500' : 'border-l-4 border-l-red-500'">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span :class="['material-symbols-rounded text-xl', permissionsData.mysql.ok ? 'text-green-500' : 'text-red-500']">{{ permissionsData.mysql.ok ? 'check_circle' : 'error' }}</span>
            <div>
              <p class="font-medium">Config File Permissions</p>
              <p class="text-sm text-surface-500">
                <template v-if="permissionsData.mysql.ok">All config files have correct permissions</template>
                <template v-else>{{ permissionsData.mysql.configs?.filter(c => !c.ok).length }} issue(s) detected</template>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button v-if="!permissionsData.mysql.ok" @click="fixPermissions('mysql')" class="btn-warning btn-sm" :disabled="permissionsFixing.mysql">
              <span v-if="permissionsFixing.mysql" class="spinner"></span>
              <span v-else class="material-symbols-rounded text-lg">build</span>
              Fix
            </button>
            <button @click="fetchPermissions('mysql')" class="btn-secondary btn-sm" :disabled="permissionsLoading.mysql"><span class="material-symbols-rounded text-lg">refresh</span></button>
          </div>
        </div>
        <details v-if="!permissionsData.mysql.ok" class="mt-3">
          <summary class="text-sm text-surface-500 cursor-pointer">View details</summary>
          <div class="mt-2 text-sm space-y-2">
            <div v-for="config in permissionsData.mysql.configs" :key="config.path" class="flex items-start gap-2 p-2 rounded bg-surface-50 dark:bg-surface-800">
              <span :class="['material-symbols-rounded text-sm mt-0.5', config.ok ? 'text-green-500' : 'text-red-500']">{{ config.ok ? 'check' : 'close' }}</span>
              <div class="flex-1 min-w-0">
                <code class="text-xs break-all">{{ config.path }}</code>
                <div v-if="config.issues?.length" class="mt-1 text-xs text-red-500"><p v-for="issue in config.issues" :key="issue">• {{ issue }}</p></div>
              </div>
            </div>
          </div>
        </details>
      </div>
      <div v-else-if="permissionsLoading.mysql" class="card p-4"><div class="flex items-center gap-3 text-surface-500"><span class="spinner"></span><span>Checking permissions...</span></div></div>

      <div v-if="mysqlLoading" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading MySQL configuration...</span>
        </div>
      </div>

      <!-- Raw Config Editor -->
      <div v-else-if="mysqlRawMode" class="card p-6 relative">
        <div v-if="mysqlRawLoading && !mysqlRawConfig" class="flex items-center justify-center py-12">
          <div class="flex items-center gap-3 text-surface-500">
            <span class="spinner"></span>
            <span>Loading {{ mysqlConfigFiles.find(f => f.path === mysqlSelectedConfigFile)?.label || 'config' }}...</span>
          </div>
        </div>
        <template v-else>
          <div class="relative">
            <ConfigEditor 
              v-model="mysqlRawConfig" 
              height="500px" 
              :zen-title="`MySQL - ${mysqlConfigFiles.find(f => f.path === mysqlSelectedConfigFile)?.label || 'config'}`"
              service="mysql"
              :config-files="mysqlConfigFiles"
              :selected-file="mysqlSelectedConfigFile"
              :loading="mysqlRawLoading"
              @save="saveMysqlRawConfig"
              @open-guide="openGuide('mysql')"
              @file-change="mysqlSelectedConfigFile = $event"
            />
            <div v-if="mysqlRawLoading" class="absolute top-2 right-2 flex items-center gap-2 bg-surface-800/90 px-3 py-1.5 rounded-lg text-xs text-surface-300">
              <span class="spinner-sm"></span>
              Loading...
            </div>
          </div>
          <div class="flex justify-end gap-3 mt-4">
            <button @click="mysqlRawMode = false" class="btn-secondary">Cancel</button>
            <button @click="saveMysqlRawConfig" class="btn-primary" :disabled="mysqlSaving || mysqlRawLoading">
              <span v-if="mysqlSaving" class="spinner"></span>
              Save & Restart
            </button>
          </div>
        </template>
      </div>

      <!-- Table-based Settings Editor -->
      <template v-else>
        <div class="card overflow-hidden">
          <div v-for="section in mysqlSections" :key="section.id">
            <div class="px-6 py-3 bg-surface-50 dark:bg-surface-800/50 border-b border-surface-200 dark:border-surface-700">
              <h4 class="font-medium flex items-center gap-2">
                <span class="material-symbols-rounded text-lg text-surface-400">{{ section.icon }}</span>
                {{ section.label }}
              </h4>
            </div>
            <table class="w-full">
              <tbody>
                <tr v-for="def in getMysqlSettingsBySection(section.id)" :key="def.key" class="border-b border-surface-100 dark:border-surface-800 hover:bg-surface-50 dark:hover:bg-surface-800/30">
                  <td class="px-6 py-4 w-1/3">
                    <div class="font-medium">{{ def.label }}</div>
                    <div class="text-xs text-surface-500 mt-0.5">{{ def.description }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <template v-if="def.type === 'toggle'">
                      <div class="flex items-center gap-3">
                        <button @click="mysqlSettings[def.key] = mysqlSettings[def.key] === 'ON' || mysqlSettings[def.key] === '1' ? 'OFF' : 'ON'" :disabled="!mysqlEditMode" :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors', (mysqlSettings[def.key] === 'ON' || mysqlSettings[def.key] === '1') ? 'bg-green-500' : 'bg-surface-300 dark:bg-surface-600', !mysqlEditMode && 'opacity-60 cursor-not-allowed']">
                          <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition-transform', (mysqlSettings[def.key] === 'ON' || mysqlSettings[def.key] === '1') ? 'translate-x-6' : 'translate-x-1']"/>
                        </button>
                        <span :class="['text-sm font-medium', (mysqlSettings[def.key] === 'ON' || mysqlSettings[def.key] === '1') ? 'text-green-500' : 'text-surface-400']">
                          {{ (mysqlSettings[def.key] === 'ON' || mysqlSettings[def.key] === '1') ? 'Enabled' : 'Disabled' }}
                        </span>
                      </div>
                    </template>
                    <template v-else-if="def.type === 'select'">
                      <select v-if="mysqlEditMode" v-model="mysqlSettings[def.key]" class="input max-w-md">
                        <option v-for="opt in def.options" :key="opt" :value="opt">{{ opt }}</option>
                      </select>
                      <span v-else class="font-mono text-sm">{{ mysqlSettings[def.key] || '-' }}</span>
                    </template>
                    <template v-else>
                      <input v-if="mysqlEditMode" v-model="mysqlSettings[def.key]" :placeholder="def.placeholder" class="input max-w-md"/>
                      <span v-else class="font-mono text-sm">{{ mysqlSettings[def.key] || '-' }}</span>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          
          <!-- Save button when in edit mode -->
          <div v-if="mysqlEditMode" class="px-6 py-4 bg-surface-50 dark:bg-surface-800/50 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-3">
            <button @click="cancelMysqlEdit" class="btn-secondary">Cancel</button>
            <button @click="saveMysqlSettings" class="btn-primary" :disabled="mysqlSaving || !mysqlHasChanges">
              <span v-if="mysqlSaving" class="spinner"></span>
              Save Changes
            </button>
          </div>
        </div>

        <!-- All Server Variables -->
        <div class="card p-6">
          <div class="flex items-center justify-between mb-4 flex-wrap gap-4">
            <h3 class="font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-orange-500">data_object</span>
              All Server Variables
            </h3>
            <div class="relative">
              <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
              <input v-model="mysqlSearchQuery" type="text" placeholder="Search variables..." class="input pl-10 w-64"/>
            </div>
          </div>
          <div class="overflow-x-auto max-h-96">
            <table class="w-full">
              <thead class="sticky top-0 bg-white dark:bg-surface-900">
                <tr class="text-left border-b border-surface-200 dark:border-surface-700">
                  <th class="pb-3 font-medium">Variable</th>
                  <th class="pb-3 font-medium">Value</th>
                </tr>
              </thead>
              <tbody class="font-mono text-sm">
                <tr v-for="v in filteredMysqlVariables" :key="v.name" class="border-b border-surface-100 dark:border-surface-800">
                  <td class="py-2 text-surface-600 dark:text-surface-400">{{ v.name }}</td>
                  <td class="py-2">{{ v.value }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>

    <!-- Postfix Tab -->
    <div v-if="activeTab === 'postfix'" class="space-y-6">
      
      <!-- 1. Service Status Header -->
      <div class="card p-4">
        <div class="flex items-center justify-between flex-wrap gap-4">
          <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-2xl text-red-500">forward_to_inbox</span>
              <h3 class="text-lg font-semibold">Postfix</h3>
            </div>
            <div class="flex items-center gap-2 px-3 py-1 rounded-full" :class="postfixStatus?.running ? 'bg-green-500/10' : 'bg-red-500/10'">
              <span :class="['w-2 h-2 rounded-full', postfixStatus?.running ? 'bg-green-500' : 'bg-red-500']"></span>
              <span :class="['text-sm font-medium', postfixStatus?.running ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400']">
                {{ postfixStatus?.running ? 'Running' : 'Stopped' }}
              </span>
            </div>
            <div class="text-sm text-surface-500">
              Queue: <span class="font-mono">{{ postfixQueue.length }}</span> messages
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button @click="flushQueue" class="btn-secondary btn-sm" :disabled="postfixSaving || postfixQueue.length === 0">
              <span class="material-symbols-rounded text-lg">outbox</span>
              Flush Queue
            </button>
            <button @click="restartPostfix" class="btn-secondary btn-sm" :disabled="postfixSaving">
              <span class="material-symbols-rounded text-lg">refresh</span>
              Restart
            </button>
          </div>
        </div>
      </div>

      <!-- 2. Protocol Ports - 3 Cards -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="card p-5 text-center hover:border-blue-500/50 transition-colors">
          <span class="material-symbols-rounded text-4xl text-blue-500 mb-3">send</span>
          <p class="font-bold text-lg">SMTP</p>
          <p class="text-sm text-surface-500 font-mono">Port 25</p>
          <p class="text-xs text-surface-400 mt-1">MTA to MTA</p>
        </div>
        <div class="card p-5 text-center hover:border-orange-500/50 transition-colors">
          <span class="material-symbols-rounded text-4xl text-orange-500 mb-3">outgoing_mail</span>
          <p class="font-bold text-lg">Submission</p>
          <p class="text-sm text-surface-500 font-mono">Port 587</p>
          <p class="text-xs text-orange-500 mt-1">STARTTLS</p>
        </div>
        <div class="card p-5 text-center hover:border-green-500/50 transition-colors">
          <span class="material-symbols-rounded text-4xl text-green-500 mb-3">enhanced_encryption</span>
          <p class="font-bold text-lg">SMTPS</p>
          <p class="text-sm text-surface-500 font-mono">Port 465</p>
          <p class="text-xs text-green-500 mt-1">SSL/TLS</p>
        </div>
      </div>

      <!-- 3. Config File Permissions - Checks ALL config files -->
      <div v-if="permissionsData.postfix" class="card p-4" :class="permissionsData.postfix.ok ? 'border-l-4 border-l-green-500' : 'border-l-4 border-l-red-500'">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span :class="['material-symbols-rounded text-xl', permissionsData.postfix.ok ? 'text-green-500' : 'text-red-500']">{{ permissionsData.postfix.ok ? 'check_circle' : 'error' }}</span>
            <div>
              <p class="font-medium">Config File Permissions</p>
              <p class="text-sm text-surface-500">
                <template v-if="permissionsData.postfix.ok">
                  All {{ permissionsData.postfix.configs?.length || 0 }} config files have correct permissions
                </template>
                <template v-else>
                  {{ permissionsData.postfix.configs?.filter(c => !c.ok).length }} of {{ permissionsData.postfix.configs?.length || 0 }} files have permission issues
                </template>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button v-if="!permissionsData.postfix.ok" @click="fixPermissions('postfix')" class="btn-warning btn-sm" :disabled="permissionsFixing.postfix">
              <span v-if="permissionsFixing.postfix" class="spinner"></span>
              <span v-else class="material-symbols-rounded text-lg">build</span>
              Fix All
            </button>
            <button @click="fetchPermissions('postfix')" class="btn-secondary btn-sm" :disabled="permissionsLoading.postfix">
              <span class="material-symbols-rounded text-lg">refresh</span>
            </button>
          </div>
        </div>
        <!-- Expandable details showing all config files -->
        <details class="mt-3">
          <summary class="text-sm text-surface-500 cursor-pointer hover:text-surface-700 dark:hover:text-surface-300">
            View all {{ permissionsData.postfix.configs?.length || 0 }} config files
          </summary>
          <div class="mt-2 text-sm space-y-2 max-h-64 overflow-y-auto">
            <div v-for="config in permissionsData.postfix.configs" :key="config.path" class="flex items-start gap-2 p-2 rounded bg-surface-50 dark:bg-surface-800">
              <span :class="['material-symbols-rounded text-sm mt-0.5', config.ok ? 'text-green-500' : 'text-red-500']">{{ config.ok ? 'check' : 'close' }}</span>
              <div class="flex-1 min-w-0">
                <code class="text-xs break-all">{{ config.path }}</code>
                <div v-if="config.issues?.length" class="mt-1 text-xs text-red-500">
                  <p v-for="issue in config.issues" :key="issue">• {{ issue }}</p>
                </div>
              </div>
            </div>
          </div>
        </details>
      </div>
      <div v-else-if="permissionsLoading.postfix" class="card p-4">
        <div class="flex items-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Checking all config file permissions...</span>
        </div>
      </div>

      <!-- 4. Configuration Card with Dropdown + Editor -->
      <div class="card p-6">
        <div class="flex items-center justify-between flex-wrap gap-4 mb-4">
          <div>
            <h4 class="font-semibold flex items-center gap-2 mb-2">
              <span class="material-symbols-rounded text-surface-400">settings</span>
              Configuration Editor
            </h4>
            <div class="flex items-center gap-2">
              <!-- Config file selector -->
              <div class="relative">
                <select 
                  v-model="postfixSelectedConfigFile" 
                  class="input pr-8 text-sm font-mono bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400 min-w-[280px]"
                >
                  <option v-for="file in postfixConfigFiles" :key="file.path" :value="file.path">
                    {{ file.label }} - {{ file.description }}
                  </option>
                </select>
              </div>
              <button @click="copyToClipboard(postfixConfigPath)" class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-800 rounded-lg transition-colors" title="Copy path">
                <span class="material-symbols-rounded text-lg text-surface-500">content_copy</span>
              </button>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <button @click="openGuide('postfix')" class="btn-secondary" title="Best Practices Guide">
              <span class="material-symbols-rounded">lightbulb</span>
              Guide
            </button>
            <button @click="postfixRawMode = !postfixRawMode" :class="['btn-secondary', postfixRawMode && 'bg-surface-200 dark:bg-surface-700']">
              <span class="material-symbols-rounded">code</span>
              Raw Edit
            </button>
            <button @click="postfixEditMode = !postfixEditMode; postfixRawMode = false" :class="['btn-primary', postfixEditMode && !postfixRawMode && 'bg-primary-600']">
              <span class="material-symbols-rounded">edit</span>
              Edit Values
            </button>
          </div>
        </div>

        <!-- Loading state -->
        <div v-if="postfixLoading" class="flex items-center justify-center py-12">
          <div class="flex items-center gap-3 text-surface-500">
            <span class="spinner"></span>
            <span>Loading Postfix configuration...</span>
          </div>
        </div>

        <!-- Raw Config Editor -->
        <div v-else-if="postfixRawMode" class="relative">
          <!-- Loading overlay - shown on top without unmounting editor -->
          <div v-if="postfixRawLoading && !postfixRawConfig" class="flex items-center justify-center py-12">
            <div class="flex items-center gap-3 text-surface-500">
              <span class="spinner"></span>
              <span>Loading {{ postfixConfigFiles.find(f => f.path === postfixSelectedConfigFile)?.label || 'config' }}...</span>
            </div>
          </div>
          <template v-else>
            <div class="relative">
              <ConfigEditor 
                v-model="postfixRawConfig" 
                height="500px" 
                :zen-title="`Postfix - ${postfixConfigFiles.find(f => f.path === postfixSelectedConfigFile)?.label || 'main.cf'}`"
                service="postfix"
                :config-files="postfixConfigFiles"
                :selected-file="postfixSelectedConfigFile"
                :loading="postfixRawLoading"
                @save="savePostfixRawConfig"
                @open-guide="openGuide('postfix')"
                @file-change="postfixSelectedConfigFile = $event"
              />
              <!-- Small loading indicator when switching files -->
              <div v-if="postfixRawLoading" class="absolute top-2 right-2 flex items-center gap-2 bg-surface-800/90 px-3 py-1.5 rounded-lg text-xs text-surface-300">
                <span class="spinner-sm"></span>
                Loading...
              </div>
            </div>
            <div class="flex justify-end gap-3 mt-4">
              <button @click="postfixRawMode = false" class="btn-secondary">Cancel</button>
              <button @click="savePostfixRawConfig" class="btn-primary" :disabled="postfixSaving || postfixRawLoading">
                <span v-if="postfixSaving" class="spinner"></span>
                Save & Restart
              </button>
            </div>
          </template>
        </div>

        <!-- Table-based Settings Editor (inside config card) -->
        <div v-else class="border-t border-surface-200 dark:border-surface-700 -mx-6 -mb-6 mt-4">
          <div v-for="section in postfixSections" :key="section.id">
            <div class="px-6 py-3 bg-surface-50 dark:bg-surface-800/50 border-b border-surface-200 dark:border-surface-700">
              <h4 class="font-medium flex items-center gap-2">
                <span class="material-symbols-rounded text-lg text-surface-400">{{ section.icon }}</span>
                {{ section.label }}
              </h4>
            </div>
            <table class="w-full">
              <tbody>
                <tr v-for="def in getPostfixSettingsBySection(section.id)" :key="def.key" class="border-b border-surface-100 dark:border-surface-800 hover:bg-surface-50 dark:hover:bg-surface-800/30">
                  <td class="px-6 py-4 w-1/3">
                    <div class="font-medium">{{ def.label }}</div>
                    <div class="text-xs text-surface-500 mt-0.5">{{ def.description }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <template v-if="def.type === 'toggle'">
                      <div class="flex items-center gap-3">
                        <button @click="postfixSettings[def.key] = postfixSettings[def.key] === 'yes' ? 'no' : 'yes'" :disabled="!postfixEditMode" :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors', postfixSettings[def.key] === 'yes' ? 'bg-green-500' : 'bg-surface-300 dark:bg-surface-600', !postfixEditMode && 'opacity-60 cursor-not-allowed']">
                          <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition-transform', postfixSettings[def.key] === 'yes' ? 'translate-x-6' : 'translate-x-1']"/>
                        </button>
                        <span :class="['text-sm font-medium', postfixSettings[def.key] === 'yes' ? 'text-green-500' : 'text-surface-400']">
                          {{ postfixSettings[def.key] === 'yes' ? 'Enabled' : 'Disabled' }}
                        </span>
                      </div>
                    </template>
                    <template v-else-if="def.type === 'select'">
                      <select v-if="postfixEditMode" v-model="postfixSettings[def.key]" class="input max-w-md">
                        <option v-for="opt in def.options" :key="opt" :value="opt">{{ opt }}</option>
                      </select>
                      <span v-else class="font-mono text-sm">{{ postfixSettings[def.key] || '-' }}</span>
                    </template>
                    <template v-else>
                      <input v-if="postfixEditMode" v-model="postfixSettings[def.key]" :placeholder="def.placeholder" class="input max-w-md"/>
                      <span v-else class="font-mono text-sm">{{ postfixSettings[def.key] || '-' }}</span>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          
          <!-- Save button when in edit mode -->
          <div v-if="postfixEditMode" class="px-6 py-4 bg-surface-50 dark:bg-surface-800/50 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-3">
            <button @click="cancelPostfixEdit" class="btn-secondary">Cancel</button>
            <button @click="savePostfixSettings" class="btn-primary" :disabled="postfixSaving || !postfixHasChanges">
              <span v-if="postfixSaving" class="spinner"></span>
              Save Changes
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Dovecot Tab -->
    <div v-if="activeTab === 'dovecot'" class="space-y-6">
      
      <!-- 1. Service Status Header -->
      <div class="card p-4">
        <div class="flex items-center justify-between flex-wrap gap-4">
          <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-2xl text-purple-500">inbox</span>
              <h3 class="text-lg font-semibold">Dovecot</h3>
            </div>
            <div class="flex items-center gap-2 px-3 py-1 rounded-full" :class="dovecotStatus?.running ? 'bg-green-500/10' : 'bg-red-500/10'">
              <span :class="['w-2 h-2 rounded-full', dovecotStatus?.running ? 'bg-green-500' : 'bg-red-500']"></span>
              <span :class="['text-sm font-medium', dovecotStatus?.running ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400']">
                {{ dovecotStatus?.running ? 'Running' : 'Stopped' }}
              </span>
            </div>
            <div class="text-sm text-surface-500">
              <span class="font-mono">{{ dovecotConnections.length }}</span> active connections
            </div>
          </div>
          <button @click="restartDovecot" class="btn-secondary btn-sm" :disabled="dovecotSaving">
            <span class="material-symbols-rounded text-lg">refresh</span>
            Restart
          </button>
        </div>
      </div>

      <!-- 2. Protocol Ports - 4 Cards -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="card p-5 text-center hover:border-primary-500/50 transition-colors">
          <span class="material-symbols-rounded text-4xl text-blue-500 mb-3">mail</span>
          <p class="font-bold text-lg">IMAP</p>
          <p class="text-sm text-surface-500 font-mono">Port 143</p>
          <p class="text-xs text-surface-400 mt-1">Unencrypted</p>
        </div>
        <div class="card p-5 text-center hover:border-green-500/50 transition-colors">
          <span class="material-symbols-rounded text-4xl text-green-500 mb-3">lock</span>
          <p class="font-bold text-lg">IMAPS</p>
          <p class="text-sm text-surface-500 font-mono">Port 993</p>
          <p class="text-xs text-green-500 mt-1">SSL/TLS</p>
        </div>
        <div class="card p-5 text-center hover:border-orange-500/50 transition-colors">
          <span class="material-symbols-rounded text-4xl text-orange-500 mb-3">download</span>
          <p class="font-bold text-lg">POP3</p>
          <p class="text-sm text-surface-500 font-mono">Port 110</p>
          <p class="text-xs text-surface-400 mt-1">Unencrypted</p>
        </div>
        <div class="card p-5 text-center hover:border-purple-500/50 transition-colors">
          <span class="material-symbols-rounded text-4xl text-purple-500 mb-3">enhanced_encryption</span>
          <p class="font-bold text-lg">POP3S</p>
          <p class="text-sm text-surface-500 font-mono">Port 995</p>
          <p class="text-xs text-green-500 mt-1">SSL/TLS</p>
        </div>
      </div>

      <!-- 3. Config File Permissions - Checks ALL config files -->
      <div v-if="permissionsData.dovecot" class="card p-4" :class="permissionsData.dovecot.ok ? 'border-l-4 border-l-green-500' : 'border-l-4 border-l-red-500'">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span :class="['material-symbols-rounded text-xl', permissionsData.dovecot.ok ? 'text-green-500' : 'text-red-500']">{{ permissionsData.dovecot.ok ? 'check_circle' : 'error' }}</span>
            <div>
              <p class="font-medium">Config File Permissions</p>
              <p class="text-sm text-surface-500">
                <template v-if="permissionsData.dovecot.ok">
                  All {{ permissionsData.dovecot.configs?.length || 0 }} config files have correct permissions
                </template>
                <template v-else>
                  {{ permissionsData.dovecot.configs?.filter(c => !c.ok).length }} of {{ permissionsData.dovecot.configs?.length || 0 }} files have permission issues
                </template>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button v-if="!permissionsData.dovecot.ok" @click="fixPermissions('dovecot')" class="btn-warning btn-sm" :disabled="permissionsFixing.dovecot">
              <span v-if="permissionsFixing.dovecot" class="spinner"></span>
              <span v-else class="material-symbols-rounded text-lg">build</span>
              Fix All
            </button>
            <button @click="fetchPermissions('dovecot')" class="btn-secondary btn-sm" :disabled="permissionsLoading.dovecot">
              <span class="material-symbols-rounded text-lg">refresh</span>
            </button>
          </div>
        </div>
        <!-- Expandable details showing all config files -->
        <details class="mt-3">
          <summary class="text-sm text-surface-500 cursor-pointer hover:text-surface-700 dark:hover:text-surface-300">
            View all {{ permissionsData.dovecot.configs?.length || 0 }} config files
          </summary>
          <div class="mt-2 text-sm space-y-2 max-h-64 overflow-y-auto">
            <div v-for="config in permissionsData.dovecot.configs" :key="config.path" class="flex items-start gap-2 p-2 rounded bg-surface-50 dark:bg-surface-800">
              <span :class="['material-symbols-rounded text-sm mt-0.5', config.ok ? 'text-green-500' : 'text-red-500']">{{ config.ok ? 'check' : 'close' }}</span>
              <div class="flex-1 min-w-0">
                <code class="text-xs break-all">{{ config.path }}</code>
                <div v-if="config.issues?.length" class="mt-1 text-xs text-red-500">
                  <p v-for="issue in config.issues" :key="issue">• {{ issue }}</p>
                </div>
              </div>
            </div>
          </div>
        </details>
      </div>
      <div v-else-if="permissionsLoading.dovecot" class="card p-4">
        <div class="flex items-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Checking all config file permissions...</span>
        </div>
      </div>

      <!-- 4. Configuration Card with Dropdown + Editor -->
      <div class="card p-6">
        <div class="flex items-center justify-between flex-wrap gap-4 mb-4">
          <div>
            <h4 class="font-semibold flex items-center gap-2 mb-2">
              <span class="material-symbols-rounded text-surface-400">settings</span>
              Configuration Editor
            </h4>
            <div class="flex items-center gap-2">
              <!-- Config file selector -->
              <div class="relative">
                <select 
                  v-model="dovecotSelectedConfigFile" 
                  class="input pr-8 text-sm font-mono bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400 min-w-[300px]"
                >
                  <option v-for="file in dovecotConfigFiles" :key="file.path" :value="file.path">
                    {{ file.label }} - {{ file.description }}
                  </option>
                </select>
              </div>
              <button @click="copyToClipboard(dovecotConfigPath)" class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-800 rounded-lg transition-colors" title="Copy path">
                <span class="material-symbols-rounded text-lg text-surface-500">content_copy</span>
              </button>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <button @click="openGuide('dovecot')" class="btn-secondary" title="Best Practices Guide">
              <span class="material-symbols-rounded">lightbulb</span>
              Guide
            </button>
            <button @click="dovecotRawMode = !dovecotRawMode" :class="['btn-secondary', dovecotRawMode && 'bg-surface-200 dark:bg-surface-700']">
              <span class="material-symbols-rounded">code</span>
              Raw Edit
            </button>
            <button @click="dovecotEditMode = !dovecotEditMode; dovecotRawMode = false" :class="['btn-primary', dovecotEditMode && !dovecotRawMode && 'bg-primary-600']">
              <span class="material-symbols-rounded">edit</span>
              Edit Values
            </button>
          </div>
        </div>

        <!-- Loading state -->
        <div v-if="dovecotLoading" class="flex items-center justify-center py-12">
          <div class="flex items-center gap-3 text-surface-500">
            <span class="spinner"></span>
            <span>Loading Dovecot configuration...</span>
          </div>
        </div>

        <!-- Raw Config Editor -->
        <div v-else-if="dovecotRawMode" class="relative">
          <div v-if="dovecotRawLoading && !dovecotRawConfig" class="flex items-center justify-center py-12">
            <div class="flex items-center gap-3 text-surface-500">
              <span class="spinner"></span>
              <span>Loading {{ dovecotConfigFiles.find(f => f.path === dovecotSelectedConfigFile)?.label || 'config' }}...</span>
            </div>
          </div>
          <template v-else>
            <div class="relative">
              <ConfigEditor 
                v-model="dovecotRawConfig" 
                height="500px" 
                :zen-title="`Dovecot - ${dovecotConfigFiles.find(f => f.path === dovecotSelectedConfigFile)?.label || 'dovecot.conf'}`"
                service="dovecot"
                :config-files="dovecotConfigFiles"
                :selected-file="dovecotSelectedConfigFile"
                :loading="dovecotRawLoading"
                @save="saveDovecotRawConfig"
                @open-guide="openGuide('dovecot')"
                @file-change="dovecotSelectedConfigFile = $event"
              />
              <div v-if="dovecotRawLoading" class="absolute top-2 right-2 flex items-center gap-2 bg-surface-800/90 px-3 py-1.5 rounded-lg text-xs text-surface-300">
                <span class="spinner-sm"></span>
                Loading...
              </div>
            </div>
            <div class="flex justify-end gap-3 mt-4">
              <button @click="dovecotRawMode = false" class="btn-secondary">Cancel</button>
              <button @click="saveDovecotRawConfig" class="btn-primary" :disabled="dovecotSaving || dovecotRawLoading">
                <span v-if="dovecotSaving" class="spinner"></span>
                Save & Restart
              </button>
            </div>
          </template>
        </div>

        <!-- Table-based Settings Editor (inside config card) -->
        <div v-else class="border-t border-surface-200 dark:border-surface-700 -mx-6 -mb-6 mt-4">
          <div v-for="section in dovecotSections" :key="section.id">
            <div class="px-6 py-3 bg-surface-50 dark:bg-surface-800/50 border-b border-surface-200 dark:border-surface-700">
              <h4 class="font-medium flex items-center gap-2">
                <span class="material-symbols-rounded text-lg text-surface-400">{{ section.icon }}</span>
                {{ section.label }}
              </h4>
            </div>
            <table class="w-full">
              <tbody>
                <tr v-for="def in getDovecotSettingsBySection(section.id)" :key="def.key" class="border-b border-surface-100 dark:border-surface-800 hover:bg-surface-50 dark:hover:bg-surface-800/30">
                  <td class="px-6 py-4 w-1/3">
                    <div class="font-medium">{{ def.label }}</div>
                    <div class="text-xs text-surface-500 mt-0.5">{{ def.description }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <template v-if="def.type === 'toggle'">
                      <div class="flex items-center gap-3">
                        <button @click="dovecotSettings[def.key] = dovecotSettings[def.key] === 'yes' ? 'no' : 'yes'" :disabled="!dovecotEditMode" :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors', dovecotSettings[def.key] === 'yes' ? 'bg-green-500' : 'bg-surface-300 dark:bg-surface-600', !dovecotEditMode && 'opacity-60 cursor-not-allowed']">
                          <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition-transform', dovecotSettings[def.key] === 'yes' ? 'translate-x-6' : 'translate-x-1']"/>
                        </button>
                        <span :class="['text-sm font-medium', dovecotSettings[def.key] === 'yes' ? 'text-green-500' : 'text-surface-400']">
                          {{ dovecotSettings[def.key] === 'yes' ? 'Enabled' : 'Disabled' }}
                        </span>
                      </div>
                    </template>
                    <template v-else-if="def.type === 'select'">
                      <select v-if="dovecotEditMode" v-model="dovecotSettings[def.key]" class="input max-w-md">
                        <option v-for="opt in def.options" :key="opt" :value="opt">{{ opt }}</option>
                      </select>
                      <span v-else class="font-mono text-sm">{{ dovecotSettings[def.key] || '-' }}</span>
                    </template>
                    <template v-else>
                      <input v-if="dovecotEditMode" v-model="dovecotSettings[def.key]" :placeholder="def.placeholder" class="input max-w-md"/>
                      <span v-else class="font-mono text-sm">{{ dovecotSettings[def.key] || '-' }}</span>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          
          <!-- Save button when in edit mode -->
          <div v-if="dovecotEditMode" class="px-6 py-4 bg-surface-50 dark:bg-surface-800/50 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-3">
            <button @click="cancelDovecotEdit" class="btn-secondary">Cancel</button>
            <button @click="saveDovecotSettings" class="btn-primary" :disabled="dovecotSaving || !dovecotHasChanges">
              <span v-if="dovecotSaving" class="spinner"></span>
              Save Changes
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- PowerDNS Tab -->
    <div v-if="activeTab === 'pdns'" class="space-y-6">
      <!-- Header with config path (2/3) + Config Permissions (1/3) -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- PowerDNS Configuration Card (2/3) -->
        <div class="lg:col-span-2 card p-6">
          <div class="flex items-center justify-between flex-wrap gap-4 mb-4">
            <div>
              <h3 class="text-lg font-semibold flex items-center gap-2">
                <span class="material-symbols-rounded text-blue-500">public</span>
                PowerDNS Configuration
              </h3>
              <div class="flex items-center gap-2 mt-2">
                <code class="text-sm bg-surface-100 dark:bg-surface-800 px-3 py-1 rounded-lg font-mono text-surface-600 dark:text-surface-400">{{ pdnsConfigPath }}</code>
                <button @click="copyToClipboard(pdnsConfigPath)" class="p-1.5 hover:bg-surface-100 dark:hover:bg-surface-800 rounded-lg transition-colors">
                  <span class="material-symbols-rounded text-lg text-surface-500">content_copy</span>
                </button>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <button @click="openGuide('pdns')" class="btn-secondary" title="Best Practices Guide">
                <span class="material-symbols-rounded">lightbulb</span>
                Guide
              </button>
              <button @click="pdnsRawMode = !pdnsRawMode; pdnsEditMode = false" :class="['btn-secondary', pdnsRawMode && 'bg-surface-200 dark:bg-surface-700']">
                <span class="material-symbols-rounded">code</span>
                Raw Edit
              </button>
              <button @click="pdnsEditMode = !pdnsEditMode; pdnsRawMode = false" :class="['btn-primary', pdnsEditMode && !pdnsRawMode && 'bg-primary-600']">
                <span class="material-symbols-rounded">edit</span>
                Edit Values
              </button>
            </div>
          </div>
          
          <!-- Status bar -->
          <div class="flex items-center justify-between flex-wrap gap-4 pt-4 border-t border-surface-200 dark:border-surface-700">
            <div class="flex items-center gap-6 flex-wrap">
              <div class="flex items-center gap-2">
                <span :class="['w-2 h-2 rounded-full', pdnsStatus?.running ? 'bg-green-500' : 'bg-red-500']"></span>
                <span class="text-sm font-medium">{{ pdnsStatus?.running ? 'Running' : 'Stopped' }}</span>
              </div>
              <div v-if="pdnsStatus?.version" class="text-sm text-surface-500">Version: <span class="font-mono">{{ pdnsStatus.version }}</span></div>
              <div v-if="pdnsStatus?.pid" class="text-sm text-surface-500">PID: <span class="font-mono">{{ pdnsStatus.pid }}</span></div>
              <div class="flex items-center gap-2">
                <span :class="['w-2 h-2 rounded-full', pdnsStatus?.dns_responding ? 'bg-green-500' : 'bg-red-500']"></span>
                <span class="text-sm text-surface-500">DNS {{ pdnsStatus?.dns_responding ? 'Responding' : 'Not Responding' }}</span>
              </div>
            </div>
            <button @click="restartPdns" class="btn-secondary btn-sm" :disabled="pdnsSaving">
              <span v-if="pdnsSaving" class="spinner"></span>
              <span v-else class="material-symbols-rounded text-lg">refresh</span>
              Restart
            </button>
          </div>
        </div>

        <!-- Config Permissions Card (1/3) -->
        <div class="lg:col-span-1">
          <div v-if="permissionsData.pdns" class="card p-4 h-full" :class="permissionsData.pdns.ok ? 'border-l-4 border-l-green-500' : 'border-l-4 border-l-red-500'">
            <div class="flex items-center justify-between mb-3">
              <div class="flex items-center gap-3">
                <span :class="['material-symbols-rounded text-xl', permissionsData.pdns.ok ? 'text-green-500' : 'text-red-500']">{{ permissionsData.pdns.ok ? 'check_circle' : 'error' }}</span>
                <div>
                  <p class="font-medium">Config File Permissions</p>
                  <p class="text-sm text-surface-500">
                    <template v-if="permissionsData.pdns.ok">All config files have correct permissions</template>
                    <template v-else>{{ permissionsData.pdns.configs?.filter(c => !c.ok).length }} issue(s) detected</template>
                  </p>
                </div>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <button v-if="!permissionsData.pdns.ok" @click="fixPermissions('pdns')" class="btn-warning btn-sm" :disabled="permissionsFixing.pdns">
                <span v-if="permissionsFixing.pdns" class="spinner"></span>
                <span v-else class="material-symbols-rounded text-lg">build</span>
                Fix
              </button>
              <button @click="fetchPermissions('pdns')" class="btn-secondary btn-sm" :disabled="permissionsLoading.pdns"><span class="material-symbols-rounded text-lg">refresh</span></button>
            </div>
            <details v-if="!permissionsData.pdns.ok" class="mt-3">
              <summary class="text-sm text-surface-500 cursor-pointer">View details</summary>
              <div class="mt-2 text-sm space-y-2">
                <div v-for="config in permissionsData.pdns.configs" :key="config.path" class="flex items-start gap-2 p-2 rounded bg-surface-50 dark:bg-surface-800">
                  <span :class="['material-symbols-rounded text-sm mt-0.5', config.ok ? 'text-green-500' : 'text-red-500']">{{ config.ok ? 'check' : 'close' }}</span>
                  <div class="flex-1 min-w-0">
                    <code class="text-xs break-all">{{ config.path }}</code>
                    <div v-if="config.issues?.length" class="mt-1 text-xs text-red-500"><p v-for="issue in config.issues" :key="issue">• {{ issue }}</p></div>
                  </div>
                </div>
              </div>
            </details>
          </div>
          <div v-else-if="permissionsLoading.pdns" class="card p-4 h-full"><div class="flex items-center gap-3 text-surface-500"><span class="spinner"></span><span>Checking permissions...</span></div></div>
        </div>
      </div>

      <!-- NS Configuration Card -->
      <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
          <h4 class="font-semibold flex items-center gap-2">
            <span class="material-symbols-rounded text-blue-500">settings</span>
            DNS Management
          </h4>
          <button 
            v-if="!nsConfigEditing"
            @click="nsConfigEditing = true" 
            class="btn-secondary btn-sm"
          >
            <span class="material-symbols-rounded">edit</span>
            Edit
          </button>
        </div>

        <div v-if="nsConfigLoading" class="flex items-center justify-center py-8 text-surface-500">
          <span class="spinner mr-2"></span>
          Loading configuration...
        </div>

        <div v-else class="space-y-4">
          <!-- DNS Management Toggle -->
          <div class="flex items-center justify-between p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-blue-500">dns</span>
              <div>
                <p class="font-medium">Manage DNS with this panel</p>
                <p class="text-xs text-surface-500">Create and manage DNS zones when adding sites</p>
              </div>
            </div>
            <button
              @click="nsConfig.enabled = !nsConfig.enabled"
              :disabled="!nsConfigEditing"
              :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                nsConfig.enabled ? 'bg-green-500' : 'bg-surface-300 dark:bg-surface-600',
                !nsConfigEditing && 'opacity-60 cursor-not-allowed']"
            >
              <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                nsConfig.enabled ? 'translate-x-6' : 'translate-x-1']"/>
            </button>
          </div>

          <!-- External DNS Info (when disabled) -->
          <div v-if="!nsConfig.enabled" class="p-4 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl">
            <div class="flex items-start gap-3">
              <span class="material-symbols-rounded text-amber-600 dark:text-amber-400">info</span>
              <div>
                <p class="text-sm font-medium text-amber-800 dark:text-amber-300">Using External DNS Provider</p>
                <p class="text-sm text-amber-700 dark:text-amber-400 mt-1">DNS zones will not be created when adding sites. Configure DNS records manually at your domain registrar or DNS provider (Cloudflare, Namecheap, etc.).</p>
              </div>
            </div>
          </div>

          <!-- NS Configuration (when enabled) -->
          <template v-if="nsConfig.enabled">
            <p class="text-sm text-surface-500">
              Configure the default nameservers used when creating new DNS zones.
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <!-- NS1 -->
              <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
                <label class="block text-sm font-medium mb-2">Primary Nameserver (NS1)</label>
                <input
                  v-if="nsConfigEditing"
                  v-model="nsConfig.ns1"
                  type="text"
                  class="input w-full font-mono"
                  placeholder="ns1.example.com"
                />
                <p v-else class="font-mono text-lg">{{ nsConfig.ns1 || 'Not configured' }}</p>
                <p class="text-xs text-surface-500 mt-1">Used as primary NS record and in SOA</p>
              </div>
              
              <!-- NS2 -->
              <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
                <label class="block text-sm font-medium mb-2">Secondary Nameserver (NS2)</label>
                <input
                  v-if="nsConfigEditing"
                  v-model="nsConfig.ns2"
                  type="text"
                  class="input w-full font-mono"
                  placeholder="ns2.example.com"
                />
                <p v-else class="font-mono text-lg">{{ nsConfig.ns2 || 'Not configured' }}</p>
                <p class="text-xs text-surface-500 mt-1">Used as secondary NS record</p>
              </div>
            </div>
          </template>

          <!-- Save/Cancel buttons when editing -->
          <div v-if="nsConfigEditing" class="flex justify-end gap-3 pt-4 border-t border-surface-200 dark:border-surface-700">
            <button @click="cancelNsConfigEdit" class="btn-secondary">
              Cancel
            </button>
            <button 
              @click="saveNsConfig" 
              class="btn-primary" 
              :disabled="nsConfigSaving || !nsConfigHasChanges"
            >
              <span v-if="nsConfigSaving" class="spinner-sm mr-1"></span>
              Save Configuration
            </button>
          </div>
        </div>
      </div>

      <!-- DNS Server Stats Card -->
      <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
          <h4 class="font-semibold flex items-center gap-2">
            <span class="material-symbols-rounded text-blue-500">dns</span>
            DNS Server Status
          </h4>
          <button 
            @click="syncAllDnsZones" 
            :disabled="dnsSyncing"
            class="btn-primary btn-sm"
          >
            <span v-if="dnsSyncing" class="spinner-sm mr-1"></span>
            <span v-else class="material-symbols-rounded text-lg mr-1">sync</span>
            {{ dnsSyncing ? 'Syncing...' : 'Sync All Zones' }}
          </button>
        </div>
        
        <div v-if="dnsStats" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <!-- NS1 -->
          <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
            <div class="flex items-center gap-2 mb-2">
              <span class="material-symbols-rounded text-green-500">cloud</span>
              <span class="font-medium">NS1 (Primary)</span>
            </div>
            <p class="text-lg font-mono">{{ dnsStats.ns1?.hostname || '-' }}</p>
            <p class="text-sm text-surface-500">{{ dnsStats.ns1?.ip || '-' }}</p>
            <div class="flex items-center gap-1 mt-2">
              <span :class="['w-2 h-2 rounded-full', dnsStats.ns1?.responding ? 'bg-green-500' : 'bg-red-500']"></span>
              <span class="text-xs text-surface-500">{{ dnsStats.ns1?.responding ? 'Responding' : 'Not responding' }}</span>
            </div>
          </div>
          
          <!-- NS2 -->
          <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
            <div class="flex items-center gap-2 mb-2">
              <span class="material-symbols-rounded text-blue-500">cloud</span>
              <span class="font-medium">NS2 (Secondary)</span>
            </div>
            <p class="text-lg font-mono">{{ dnsStats.ns2?.hostname || '-' }}</p>
            <p class="text-sm text-surface-500">{{ dnsStats.ns2?.ip || '-' }}</p>
            <div class="flex items-center gap-1 mt-2">
              <span :class="['w-2 h-2 rounded-full', dnsStats.ns2?.responding ? 'bg-green-500' : 'bg-red-500']"></span>
              <span class="text-xs text-surface-500">{{ dnsStats.ns2?.responding ? 'Responding' : 'Not responding' }}</span>
            </div>
          </div>
          
          <!-- Zone Count -->
          <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
            <div class="flex items-center gap-2 mb-2">
              <span class="material-symbols-rounded text-amber-500">folder</span>
              <span class="font-medium">Zones</span>
            </div>
            <p class="text-2xl font-bold">{{ dnsStats.zone_count || 0 }}</p>
            <p class="text-sm text-surface-500">Total DNS zones</p>
          </div>
          
          <!-- Last Sync -->
          <div class="p-4 bg-surface-50 dark:bg-surface-800 rounded-xl">
            <div class="flex items-center gap-2 mb-2">
              <span class="material-symbols-rounded text-purple-500">schedule</span>
              <span class="font-medium">Last Sync</span>
            </div>
            <p class="text-lg font-medium">{{ dnsStats.last_sync || 'Never' }}</p>
            <p class="text-sm text-surface-500">{{ dnsStats.last_sync_relative || '-' }}</p>
          </div>
        </div>
        
        <div v-else-if="dnsStatsLoading" class="flex items-center justify-center py-8 text-surface-500">
          <span class="spinner mr-2"></span>
          Loading DNS stats...
        </div>
        
        <div v-else class="text-center py-8 text-surface-500">
          <span class="material-symbols-rounded text-3xl mb-2 block">error</span>
          <p>Failed to load DNS stats</p>
          <button @click="fetchDnsStats" class="btn-secondary btn-sm mt-2">
            <span class="material-symbols-rounded">refresh</span>
            Retry
          </button>
        </div>
      </div>

      <div v-if="pdnsLoading" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading PowerDNS configuration...</span>
        </div>
      </div>

      <!-- Raw Config Editor -->
      <div v-else-if="pdnsRawMode" class="card p-6">
        <div class="mb-4 p-4 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl">
          <div class="flex items-start gap-3">
            <span class="material-symbols-rounded text-amber-600 dark:text-amber-400">warning</span>
            <div>
              <p class="text-sm font-medium text-amber-800 dark:text-amber-300">Be careful with syntax!</p>
              <p class="text-sm text-amber-700 dark:text-amber-400 mt-1">Invalid configuration will prevent PowerDNS from starting.</p>
            </div>
          </div>
        </div>
        <ConfigEditor 
          v-model="pdnsConfig" 
          height="500px" 
          zen-title="PowerDNS Configuration - pdns.conf"
          service="pdns"
          @save="savePdnsConfig"
          @open-guide="openGuide('pdns')"
        />
        <div class="flex justify-end gap-3 mt-4">
          <button @click="cancelPdnsEdit" class="btn-secondary">Cancel</button>
          <button @click="savePdnsConfig" class="btn-primary" :disabled="pdnsSaving || pdnsConfig === pdnsOriginalConfig">
            <span v-if="pdnsSaving" class="spinner"></span>
            Save & Restart
          </button>
        </div>
      </div>

      <!-- Table-based Settings Editor -->
      <div v-else class="card overflow-hidden">
        <div v-for="section in pdnsSections" :key="section.id">
          <div class="px-6 py-3 bg-surface-50 dark:bg-surface-800/50 border-b border-surface-200 dark:border-surface-700">
            <h4 class="font-medium flex items-center gap-2">
              <span class="material-symbols-rounded text-lg text-surface-400">{{ section.icon }}</span>
              {{ section.label }}
            </h4>
          </div>
          <table class="w-full">
            <tbody>
              <tr v-for="def in getPdnsSettingsBySection(section.id)" :key="def.key" class="border-b border-surface-100 dark:border-surface-800 hover:bg-surface-50 dark:hover:bg-surface-800/30">
                <td class="px-6 py-4 w-1/3">
                  <div class="font-medium">{{ def.label }}</div>
                  <div class="text-xs text-surface-500 mt-0.5">{{ def.description }}</div>
                </td>
                <td class="px-6 py-4">
                  <template v-if="def.type === 'toggle'">
                    <div class="flex items-center gap-3">
                      <button
                        @click="pdnsSettings[def.key] = pdnsSettings[def.key] === 'yes' ? 'no' : 'yes'"
                        :disabled="!pdnsEditMode"
                        :class="['relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                          pdnsSettings[def.key] === 'yes' ? 'bg-green-500' : 'bg-surface-300 dark:bg-surface-600',
                          !pdnsEditMode && 'opacity-60 cursor-not-allowed']"
                      >
                        <span :class="['inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                          pdnsSettings[def.key] === 'yes' ? 'translate-x-6' : 'translate-x-1']"/>
                      </button>
                      <span :class="['text-sm font-medium', pdnsSettings[def.key] === 'yes' ? 'text-green-500' : 'text-surface-400']">
                        {{ pdnsSettings[def.key] === 'yes' ? 'Enabled' : 'Disabled' }}
                      </span>
                    </div>
                  </template>
                  <template v-else-if="def.type === 'password'">
                    <input
                      v-if="pdnsEditMode"
                      v-model="pdnsSettings[def.key]"
                      type="password"
                      class="input max-w-md"
                    />
                    <span v-else class="font-mono text-sm text-surface-400">********</span>
                  </template>
                  <template v-else>
                    <input
                      v-if="pdnsEditMode"
                      v-model="pdnsSettings[def.key]"
                      type="text"
                      class="input max-w-md"
                    />
                    <span v-else class="font-mono text-sm">{{ pdnsSettings[def.key] || '-' }}</span>
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Save button when in edit mode -->
        <div v-if="pdnsEditMode" class="px-6 py-4 bg-surface-50 dark:bg-surface-800/50 border-t border-surface-200 dark:border-surface-700 flex justify-end gap-3">
          <button @click="cancelPdnsEdit" class="btn-secondary">Cancel</button>
          <button @click="savePdnsSettings" class="btn-primary" :disabled="pdnsSaving || !pdnsHasChanges">
            <span v-if="pdnsSaving" class="spinner"></span>
            Save Changes
          </button>
        </div>
      </div>
    </div>

    <!-- Logs Tab -->
    <div v-if="activeTab === 'logs'" class="space-y-6">
      <div class="flex flex-wrap gap-2 items-center justify-between">
        <div class="flex flex-wrap gap-2">
          <button v-for="svc in logsServices" :key="svc.id" @click="changeLogsService(svc.id)" :class="['btn-sm flex items-center gap-2 transition-all', logsService === svc.id ? 'btn-primary' : 'btn-secondary']">
            <span class="material-symbols-rounded text-lg">{{ svc.icon }}</span>{{ svc.label }}
          </button>
        </div>
        <button @click="toggleLogsAiPanel" :class="['btn-sm flex items-center gap-2 transition-all', logsAiPanelOpen ? 'btn-primary' : 'btn-secondary']">
          <span class="material-symbols-rounded">psychology</span>
          AI Assistant
        </button>
      </div>
      <div class="flex flex-wrap items-center gap-4">
        <div v-if="logsService === 'php' && filteredPhpVersions.length > 0" class="flex items-center gap-2">
          <label class="text-sm text-surface-500">PHP Version:</label>
          <select v-model="logsPhpVersion" @change="fetchLogs" class="input w-auto"><option v-for="v in filteredPhpVersions" :key="v.version" :value="v.version">PHP {{ v.version }}</option></select>
        </div>
        <div class="flex items-center gap-2">
          <label class="text-sm text-surface-500">Log Type:</label>
          <select v-model="logsType" @change="fetchLogs" class="input w-auto"><option v-for="t in logsAvailableTypes" :key="t.id" :value="t.id" :disabled="!t.exists">{{ t.label }} {{ t.exists ? `(${t.size_human})` : '(N/A)' }}</option></select>
        </div>
        <div class="flex items-center gap-2 flex-1 min-w-[200px]">
          <input v-model="logsSearch" type="text" class="input w-full" placeholder="Search logs..." @keyup.enter="fetchLogs"/>
          <button @click="fetchLogs" class="btn-secondary btn-sm"><span class="material-symbols-rounded">search</span></button>
        </div>
        <button @click="fetchLogs" class="btn-secondary btn-sm" :disabled="logsLoading"><span class="material-symbols-rounded" :class="{ 'animate-spin': logsLoading }">refresh</span>Refresh</button>
      </div>
      <div v-if="Object.keys(logsAvailableFilters).length > 0" class="flex flex-wrap gap-2">
        <span class="text-sm text-surface-500 self-center mr-2">Quick Filters:</span>
        <button v-for="(patterns, filterName) in logsAvailableFilters" :key="filterName" @click="applyLogsFilter(filterName)" :class="['px-3 py-1.5 text-xs rounded-full border transition-all font-medium', logsFilter === filterName ? 'bg-primary-500 text-white border-primary-500' : 'bg-surface-100 dark:bg-surface-800 border-surface-200 dark:border-surface-700 hover:bg-surface-200 dark:hover:bg-surface-700']">{{ filterName.replace(/_/g, ' ') }}</button>
        <button v-if="logsFilter" @click="logsFilter = ''; fetchLogs()" class="px-3 py-1.5 text-xs rounded-full border bg-red-100 dark:bg-red-500/20 border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-500/30 font-medium"><span class="material-symbols-rounded text-sm mr-1">close</span>Clear</button>
      </div>
      
      <!-- Split view: Logs + AI Panel -->
      <div :class="['flex gap-4', logsAiPanelOpen ? 'logs-split-view' : '']">
        <!-- Logs Panel -->
        <div :class="logsAiPanelOpen ? 'w-1/2' : 'w-full'">
          <div class="card">
            <div class="card-header flex items-center justify-between">
              <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-surface-400">article</span>
                <h3 class="font-medium">{{ logsServices.find(s => s.id === logsService)?.label }} Logs <span v-if="logsTotal" class="text-sm font-normal text-surface-500">({{ logsTotal }} lines)</span></h3>
              </div>
              <div class="flex items-center gap-2">
                <div v-if="logsFilter" class="badge badge-info mr-2">Filtered: {{ logsFilter }}</div>
                <template v-if="logsAiPanelOpen">
                  <button @click="selectAllVisibleErrors" class="btn-secondary btn-xs" title="Select all errors">
                    <span class="material-symbols-rounded text-sm">select_all</span>
                    Select Errors
                  </button>
                  <button v-if="logsSelectedLines.length > 0" @click="clearLogSelection" class="btn-secondary btn-xs" title="Clear selection">
                    <span class="material-symbols-rounded text-sm">deselect</span>
                  </button>
                  <button v-if="logsSelectedLines.length > 0" @click="sendSelectedLogsToAi" class="btn-primary btn-xs" title="Send to AI">
                    <span class="material-symbols-rounded text-sm">send</span>
                    Analyze ({{ logsSelectedLines.length }})
                  </button>
                </template>
              </div>
            </div>
            <div v-if="logsLoading" class="p-8 text-center"><span class="spinner"></span><p class="text-surface-500 mt-2">Loading logs...</p></div>
            <div v-else-if="logsLines.length" class="bg-surface-900 dark:bg-surface-950 rounded-b-xl overflow-hidden">
              <div class="max-h-[600px] overflow-y-auto">
                <div 
                  v-for="(line, index) in logsLines" 
                  :key="index" 
                  @click="logsAiPanelOpen && toggleLogLineSelection(index)"
                  :class="[
                    'border-l-4 px-3 py-2 transition-colors',
                    getLogLevelClasses(parseLogLine(line).level),
                    logsAiPanelOpen ? 'cursor-pointer hover:bg-surface-800/70' : 'hover:bg-surface-800/50',
                    logsSelectedLines.includes(index) ? 'bg-primary-500/20 ring-1 ring-primary-500/50' : ''
                  ]"
                >
                  <div class="flex items-start gap-3">
                    <span v-if="logsAiPanelOpen" class="shrink-0 mt-0.5">
                      <span v-if="logsSelectedLines.includes(index)" class="material-symbols-rounded text-primary-400 text-sm">check_box</span>
                      <span v-else class="material-symbols-rounded text-surface-600 text-sm">check_box_outline_blank</span>
                    </span>
                    <span class="inline-flex items-center justify-center px-1.5 py-0.5 text-[10px] font-bold rounded shrink-0 w-8" :class="getLogLevelBadge(parseLogLine(line).level).class">{{ getLogLevelBadge(parseLogLine(line).level).text }}</span>
                    <span v-if="parseLogLine(line).timestamp" class="text-[11px] text-surface-500 shrink-0 font-mono">{{ parseLogLine(line).timestamp }}</span>
                    <span class="text-xs break-all flex-1" :class="getLogLevelColor(parseLogLine(line).level)">{{ parseLogLine(line).message }}</span>
                  </div>
                </div>
              </div>
            </div>
            <div v-else class="p-12 text-center text-surface-400"><span class="material-symbols-rounded text-4xl mb-2 block">article</span><p>No log entries found</p></div>
          </div>
        </div>
        
        <!-- AI Assistant Panel -->
        <div v-if="logsAiPanelOpen" class="w-1/2">
          <div class="card h-full flex flex-col bg-surface-900 border-surface-700">
            <div class="card-header flex items-center justify-between border-b border-surface-700">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-primary-400">psychology</span>
                <h3 class="font-medium text-white">AI Log Analyzer</h3>
              </div>
              <button @click="toggleLogsAiPanel" class="p-1 hover:bg-surface-700 rounded">
                <span class="material-symbols-rounded text-surface-400">close</span>
              </button>
            </div>
            
            <!-- Messages -->
            <div class="flex-1 overflow-y-auto p-4 space-y-4 min-h-[400px] max-h-[500px]">
              <div v-if="logsAiMessages.length === 0" class="text-center py-8">
                <span class="material-symbols-rounded text-4xl text-primary-400/50 mb-3 block">psychology</span>
                <p class="text-surface-400 text-sm mb-2">AI Log Analyzer</p>
                <p class="text-surface-500 text-xs mb-4">Select log entries and click "Analyze" to get help</p>
                <div class="space-y-2 text-xs text-surface-500">
                  <p>The AI can:</p>
                  <ul class="text-left max-w-[200px] mx-auto space-y-1">
                    <li class="flex items-center gap-2"><span class="material-symbols-rounded text-sm text-green-400">check</span> Explain error causes</li>
                    <li class="flex items-center gap-2"><span class="material-symbols-rounded text-sm text-green-400">check</span> Suggest fix commands</li>
                    <li class="flex items-center gap-2"><span class="material-symbols-rounded text-sm text-green-400">check</span> Debug PHP errors</li>
                    <li class="flex items-center gap-2"><span class="material-symbols-rounded text-sm text-green-400">check</span> Identify patterns</li>
                  </ul>
                </div>
              </div>
              
              <template v-else>
                <div 
                  v-for="(msg, idx) in logsAiMessages" 
                  :key="idx"
                  :class="['rounded-lg p-3', msg.role === 'user' ? 'bg-primary-500/20 ml-8' : 'bg-surface-800 mr-4']"
                >
                  <div v-if="msg.role === 'assistant'" class="text-sm text-surface-200 logs-ai-content" v-html="renderLogsAiMarkdown(msg.content)"></div>
                  <div v-else class="text-sm text-surface-200">{{ msg.content }}</div>
                </div>
              </template>
              
              <div v-if="logsAiTyping" class="bg-surface-800 rounded-lg p-3 mr-4">
                <div class="flex items-center gap-2 text-surface-400 text-sm">
                  <span class="material-symbols-rounded animate-spin text-primary-400">sync</span>
                  <span>Analyzing logs...</span>
                </div>
              </div>
            </div>
            
            <!-- Input -->
            <div class="p-4 border-t border-surface-700">
              <div class="flex gap-2">
                <textarea
                  v-model="logsAiInput"
                  @keydown="handleLogsAiKeydown"
                  :disabled="logsAiTyping"
                  placeholder="Ask about errors, request commands..."
                  rows="2"
                  class="flex-1 bg-surface-800 border border-surface-600 rounded-lg px-3 py-2 text-sm text-white placeholder-surface-500 focus:border-primary-500 focus:outline-none resize-none"
                ></textarea>
                <button 
                  @click="sendLogsAiChat" 
                  :disabled="!logsAiInput.trim() || logsAiTyping"
                  class="btn-primary px-3 self-end"
                >
                  <span class="material-symbols-rounded">send</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Email App Tab -->
    <div v-if="activeTab === 'emailapp'" class="space-y-6">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h3 class="text-lg font-semibold flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">mail</span>
            Email App Services
          </h3>
          <p class="text-sm text-surface-500 mt-1">Custom Node.js services for the email application</p>
        </div>
        <div class="flex gap-2">
          <button 
            @click="emailAppConfigMode = !emailAppConfigMode" 
            :class="['btn-secondary', emailAppConfigMode && 'ring-2 ring-primary-500']"
          >
            <span class="material-symbols-rounded">{{ emailAppConfigMode ? 'visibility' : 'edit' }}</span>
            <span class="hidden sm:inline">{{ emailAppConfigMode ? 'View Mode' : 'Edit Config' }}</span>
          </button>
          <button @click="fetchEmailAppServices" class="btn-secondary" :disabled="emailAppLoading">
            <span class="material-symbols-rounded" :class="emailAppLoading && 'animate-spin'">refresh</span>
            <span class="hidden sm:inline">Refresh</span>
          </button>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="emailAppLoading" class="card p-12">
        <div class="flex items-center justify-center gap-3 text-surface-500">
          <span class="spinner"></span>
          <span>Loading services...</span>
        </div>
      </div>

      <template v-else>
        <!-- Config Editor Mode -->
        <div v-if="emailAppConfigMode" class="card p-4 sm:p-6">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-cyan-500">settings</span>
              <h4 class="font-semibold">Service Configuration</h4>
            </div>
            <div class="flex items-center gap-2">
              <select v-model="emailAppSelectedService" class="input text-sm min-w-[200px]">
                <option v-for="file in emailAppConfigFiles" :key="file.service" :value="file.service">
                  {{ file.label }}
                </option>
              </select>
            </div>
          </div>
          
          <div class="text-xs text-surface-500 font-mono mb-3 flex items-center gap-2">
            <span class="material-symbols-rounded text-sm">folder</span>
            {{ emailAppConfigPath }}
          </div>

          <div v-if="emailAppConfigLoading" class="flex items-center justify-center py-12">
            <div class="flex items-center gap-3 text-surface-500">
              <span class="spinner"></span>
              <span>Loading config...</span>
            </div>
          </div>
          <div v-else>
            <ConfigEditor 
              v-model="emailAppRawConfig" 
              height="400px" 
              :zen-title="`Email App - ${emailAppConfigFiles.find(f => f.service === emailAppSelectedService)?.label || 'config'}`"
              service="emailapp"
              :config-files="emailAppConfigFiles.map(f => ({ path: f.path, label: f.label, description: f.description }))"
              :selected-file="emailAppConfigPath"
              :loading="emailAppConfigLoading"
              @save="saveEmailAppConfig"
            />
          </div>

          <div class="mt-4 p-3 bg-cyan-50 dark:bg-cyan-500/10 border border-cyan-200 dark:border-cyan-500/20 rounded-lg">
            <p class="text-sm text-cyan-700 dark:text-cyan-400 flex items-start gap-2">
              <span class="material-symbols-rounded text-base mt-0.5">info</span>
              <span>After saving changes, run <code class="bg-cyan-100 dark:bg-cyan-900/50 px-1 rounded">systemctl daemon-reload</code> and restart the service.</span>
            </p>
          </div>
        </div>

        <!-- Custom Email App Services - Side by Side -->
        <div v-else class="card p-4 sm:p-6">
          <h4 class="font-semibold mb-4 flex items-center gap-2 text-surface-700 dark:text-surface-300">
            <span class="material-symbols-rounded text-cyan-500">hub</span>
            Custom Node.js Services
          </h4>
          
          <!-- Side by side grid -->
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div v-for="def in emailAppServiceDefs" :key="def.name" class="border border-surface-200 dark:border-surface-700 rounded-xl overflow-hidden">
              <!-- Service Header -->
              <div class="p-4 bg-surface-50 dark:bg-surface-800/50">
                <div class="flex items-center justify-between gap-3 mb-3">
                  <div class="flex items-center gap-3">
                    <div :class="[
                      'w-10 h-10 rounded-xl flex items-center justify-center shrink-0',
                      getEmailAppService(def.name)?.active 
                        ? 'bg-green-100 dark:bg-green-500/20' 
                        : 'bg-red-100 dark:bg-red-500/20'
                    ]">
                      <span :class="[
                        'material-symbols-rounded text-xl',
                        getEmailAppService(def.name)?.active ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'
                      ]">{{ def.icon }}</span>
                    </div>
                    <div>
                      <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-semibold">{{ def.name }}</span>
                        <span class="text-xs font-mono px-2 py-0.5 rounded-full bg-surface-200 dark:bg-surface-700">:{{ def.port }}</span>
                      </div>
                      <span v-if="getEmailAppService(def.name)?.active" class="text-xs font-medium text-green-600 dark:text-green-400">Running</span>
                      <span v-else class="text-xs font-medium text-red-600 dark:text-red-400">Stopped</span>
                    </div>
                  </div>
                  
                  <!-- Actions -->
                  <div class="flex items-center gap-1 shrink-0">
                    <button
                      v-if="getEmailAppService(def.name)?.active"
                      @click="performEmailAppServiceAction(getEmailAppService(def.name), 'restart')"
                      class="btn-ghost btn-sm"
                      :disabled="emailAppActionLoading[def.name]"
                      title="Restart"
                    >
                      <span v-if="emailAppActionLoading[def.name] === 'restart'" class="spinner-sm"></span>
                      <span v-else class="material-symbols-rounded">restart_alt</span>
                    </button>
                    <button
                      v-if="getEmailAppService(def.name)?.active"
                      @click="performEmailAppServiceAction(getEmailAppService(def.name), 'stop')"
                      class="btn-ghost btn-sm text-red-500"
                      :disabled="emailAppActionLoading[def.name]"
                      title="Stop"
                    >
                      <span v-if="emailAppActionLoading[def.name] === 'stop'" class="spinner-sm"></span>
                      <span v-else class="material-symbols-rounded">stop_circle</span>
                    </button>
                    <button
                      v-if="!getEmailAppService(def.name)?.active"
                      @click="performEmailAppServiceAction(getEmailAppService(def.name) || { name: def.name }, 'start')"
                      class="btn-primary btn-sm"
                      :disabled="emailAppActionLoading[def.name]"
                      title="Start"
                    >
                      <span v-if="emailAppActionLoading[def.name] === 'start'" class="spinner-sm"></span>
                      <span v-else class="material-symbols-rounded">play_circle</span>
                    </button>
                    <button
                      @click="toggleEmailAppLogs(def.name)"
                      class="btn-ghost btn-sm"
                      title="View Logs"
                    >
                      <span class="material-symbols-rounded" :class="emailAppLogsExpanded[def.name] ? 'text-primary-500' : ''">article</span>
                    </button>
                  </div>
                </div>
                
                <p class="text-xs text-surface-500">{{ def.description }}</p>

                <!-- Service Details -->
                <div v-if="getEmailAppService(def.name)" class="mt-3 pt-3 border-t border-surface-200 dark:border-surface-700 grid grid-cols-4 gap-2 text-xs">
                  <div>
                    <span class="text-surface-500">Enabled</span>
                    <p :class="getEmailAppService(def.name).enabled ? 'text-green-500' : 'text-surface-400'">
                      {{ getEmailAppService(def.name).enabled ? 'Yes' : 'No' }}
                    </p>
                  </div>
                  <div>
                    <span class="text-surface-500">PID</span>
                    <p class="font-mono">{{ getEmailAppService(def.name).pid || '-' }}</p>
                  </div>
                  <div>
                    <span class="text-surface-500">Memory</span>
                    <p>{{ getEmailAppService(def.name).memory || '-' }}</p>
                  </div>
                  <div>
                    <span class="text-surface-500">Uptime</span>
                    <p>{{ getEmailAppService(def.name).uptime || '-' }}</p>
                  </div>
                </div>
              </div>

              <!-- Logs Panel -->
              <div v-if="emailAppLogsExpanded[def.name]" class="border-t border-surface-200 dark:border-surface-700">
                <div class="p-2 bg-surface-100 dark:bg-surface-800 flex items-center justify-between">
                  <span class="text-xs font-medium flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm">article</span>
                    Logs
                  </span>
                  <button 
                    @click="fetchEmailAppLogs(def.name)" 
                    class="btn-ghost btn-xs"
                    :disabled="emailAppLogsLoading[def.name]"
                  >
                    <span class="material-symbols-rounded text-sm" :class="emailAppLogsLoading[def.name] && 'animate-spin'">refresh</span>
                  </button>
                </div>
                <div v-if="emailAppLogsLoading[def.name]" class="p-4 flex items-center justify-center">
                  <span class="spinner-sm"></span>
                </div>
                <div v-else class="p-2">
                  <pre class="text-[10px] font-mono bg-surface-900 text-green-400 p-2 rounded-lg overflow-x-auto max-h-48 overflow-y-auto">{{ emailAppLogs[def.name]?.logs || 'No logs available' }}</pre>
                </div>
              </div>
            </div>
          </div>

          <!-- Commands Reference -->
          <div class="mt-4 p-3 bg-surface-100 dark:bg-surface-800 rounded-xl">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs font-mono">
              <div class="flex items-center gap-2">
                <span class="text-surface-500">Status:</span>
                <code class="bg-surface-200 dark:bg-surface-700 px-2 py-0.5 rounded">systemctl status [service]</code>
              </div>
              <div class="flex items-center gap-2">
                <span class="text-surface-500">Logs:</span>
                <code class="bg-surface-200 dark:bg-surface-700 px-2 py-0.5 rounded">journalctl -u [service] -n 50</code>
              </div>
            </div>
          </div>
        </div>

        <!-- Infrastructure Services -->
        <div class="card p-4 sm:p-6">
          <h4 class="font-semibold mb-4 flex items-center gap-2 text-surface-700 dark:text-surface-300">
            <span class="material-symbols-rounded text-amber-500">dns</span>
            Infrastructure Services
            <span class="text-xs font-normal text-surface-500 ml-2">(shared)</span>
          </h4>
          
          <div class="overflow-x-auto">
            <table class="table min-w-[500px]">
              <thead>
                <tr class="bg-surface-50 dark:bg-surface-800/50">
                  <th>Service</th>
                  <th>Purpose</th>
                  <th>Status</th>
                  <th class="text-right">Config</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="name in emailAppInfraServices" :key="name">
                  <td>
                    <div class="flex items-center gap-2">
                      <div :class="[
                        'w-8 h-8 rounded-lg flex items-center justify-center',
                        getEmailAppService(name)?.active 
                          ? 'bg-green-100 dark:bg-green-500/20' 
                          : 'bg-red-100 dark:bg-red-500/20'
                      ]">
                        <span :class="[
                          'material-symbols-rounded text-lg',
                          getEmailAppService(name)?.active ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'
                        ]">
                          {{ name === 'lsws' ? 'bolt' : name === 'redis' ? 'memory' : name === 'mariadb' ? 'database' : name === 'dovecot' ? 'inbox' : 'forward_to_inbox' }}
                        </span>
                      </div>
                      <span class="font-medium">{{ name }}</span>
                    </div>
                  </td>
                  <td class="text-sm text-surface-500">
                    {{ name === 'lsws' ? 'Web Server' : name === 'redis' ? 'Cache & Pub/Sub' : name === 'mariadb' ? 'Database' : name === 'dovecot' ? 'IMAP Server' : 'SMTP Server' }}
                  </td>
                  <td>
                    <span v-if="getEmailAppService(name)?.active" class="text-xs font-medium px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400">Running</span>
                    <span v-else class="text-xs font-medium px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-500/20 text-red-600 dark:text-red-400">Stopped</span>
                  </td>
                  <td class="text-right">
                    <button 
                      v-if="['ols', 'mysql', 'postfix', 'dovecot'].includes(name === 'lsws' ? 'ols' : name === 'mariadb' ? 'mysql' : name)"
                      @click="activeTab = name === 'lsws' ? 'ols' : name === 'mariadb' ? 'mysql' : name"
                      class="btn-ghost btn-sm"
                    >
                      <span class="material-symbols-rounded">settings</span>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>

    <!-- Create Swap Modal -->
    <Modal :show="showCreateSwapModal" title="Create Swap File" @close="showCreateSwapModal = false">
      <form @submit.prevent="createSwap" class="space-y-4">
        <div><label class="block text-sm font-medium mb-2">Swap Size</label><select v-model="newSwap.size" class="input"><option value="512M">512 MB</option><option value="1G">1 GB</option><option value="2G">2 GB</option><option value="4G">4 GB</option><option value="8G">8 GB</option></select></div>
        <div><label class="block text-sm font-medium mb-2">Swap File Path</label><input v-model="newSwap.path" type="text" class="input" placeholder="/swapfile"/></div>
        <div class="flex justify-end gap-3 pt-4"><button type="button" @click="showCreateSwapModal = false" class="btn-secondary">Cancel</button><button type="submit" class="btn-primary" :disabled="swapSaving"><span v-if="swapSaving" class="spinner"></span>Create Swap</button></div>
      </form>
    </Modal>

    <!-- Reboot Confirmation Modal -->
    <Modal :show="showRebootModal" title="Reboot Server" @close="showRebootModal = false">
      <div class="space-y-4">
        <div class="flex items-center gap-3 p-4 bg-red-50 dark:bg-red-500/10 rounded-xl border border-red-200 dark:border-red-500/20">
          <span class="material-symbols-rounded text-red-500 text-2xl">warning</span>
          <div><p class="font-medium text-red-600 dark:text-red-400">Reboot the server?</p><p class="text-sm text-surface-600 dark:text-surface-400">All services will be temporarily unavailable.</p></div>
        </div>
        <div class="flex justify-end gap-3 pt-4"><button @click="showRebootModal = false" class="btn-secondary">Cancel</button><button @click="rebootServer" class="btn-danger" :disabled="rebooting"><span v-if="rebooting" class="spinner"></span><span class="material-symbols-rounded">restart_alt</span>Reboot Now</button></div>
      </div>
    </Modal>

    <!-- Best Practices Guide Modal -->
    <ConfigGuideModal
      :visible="showGuide"
      :service="guideService"
      :currentSettings="guideCurrentSettings"
      :isLive="isGuideLive"
      :hostname="currentHostname || 'devcon1.hu'"
      :siteCount="siteCount"
      @close="showGuide = false"
      @open-file="handleOpenFile"
    />

    <!-- DNS Sync Results Modal -->
    <Modal :show="dnsSyncModal" title="DNS Zone Sync Results" @close="dnsSyncModal = false" size="lg">
      <div class="space-y-4">
        <!-- Loading state -->
        <div v-if="dnsSyncing" class="flex flex-col items-center justify-center py-8">
          <span class="spinner mb-4"></span>
          <p class="text-surface-600 dark:text-surface-400">Syncing zones to secondary nameserver...</p>
        </div>

        <!-- Results -->
        <template v-else-if="dnsSyncResult">
          <!-- Success summary -->
          <div v-if="dnsSyncResult.success" class="grid grid-cols-3 gap-4">
            <div class="text-center p-4 bg-green-50 dark:bg-green-500/10 rounded-xl">
              <p class="text-3xl font-bold text-green-600 dark:text-green-400">{{ dnsSyncResult.zones_synced }}</p>
              <p class="text-sm text-green-600/70 dark:text-green-400/70">Synced</p>
            </div>
            <div class="text-center p-4 bg-red-50 dark:bg-red-500/10 rounded-xl">
              <p class="text-3xl font-bold text-red-600 dark:text-red-400">{{ dnsSyncResult.zones_failed?.length || 0 }}</p>
              <p class="text-sm text-red-600/70 dark:text-red-400/70">Failed</p>
            </div>
            <div class="text-center p-4 bg-surface-100 dark:bg-surface-700 rounded-xl">
              <p class="text-3xl font-bold">{{ dnsSyncResult.total_zones }}</p>
              <p class="text-sm text-surface-500">Total</p>
            </div>
          </div>

          <!-- Error state -->
          <div v-else class="p-4 bg-red-50 dark:bg-red-500/10 rounded-xl border border-red-200 dark:border-red-500/20">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-red-500 text-2xl">error</span>
              <div>
                <p class="font-medium text-red-600 dark:text-red-400">Sync Failed</p>
                <p class="text-sm text-surface-600 dark:text-surface-400">{{ dnsSyncResult.error }}</p>
              </div>
            </div>
          </div>

          <!-- Failed zones list -->
          <div v-if="dnsSyncResult.zones_failed?.length > 0" class="space-y-2">
            <h4 class="font-medium text-red-600 dark:text-red-400 flex items-center gap-2">
              <span class="material-symbols-rounded">warning</span>
              Failed Zones ({{ dnsSyncResult.zones_failed.length }})
            </h4>
            <div class="max-h-64 overflow-y-auto space-y-2">
              <div 
                v-for="zone in dnsSyncResult.zones_failed" 
                :key="zone"
                class="flex items-center justify-between p-3 bg-red-50 dark:bg-red-500/10 rounded-lg border border-red-200 dark:border-red-500/20"
              >
                <div class="flex items-center gap-2">
                  <span class="material-symbols-rounded text-red-500">error</span>
                  <span class="font-mono text-sm">{{ zone }}</span>
                </div>
                <button 
                  @click="syncSingleZone(zone)"
                  :disabled="dnsSyncingZone === zone"
                  class="btn-secondary btn-sm"
                >
                  <span v-if="dnsSyncingZone === zone" class="spinner-sm mr-1"></span>
                  <span v-else class="material-symbols-rounded text-lg mr-1">sync</span>
                  {{ dnsSyncingZone === zone ? 'Syncing...' : 'Retry' }}
                </button>
              </div>
            </div>
          </div>

          <!-- Success message -->
          <div v-if="dnsSyncResult.success && dnsSyncResult.zones_failed?.length === 0" class="p-4 bg-green-50 dark:bg-green-500/10 rounded-xl border border-green-200 dark:border-green-500/20">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-green-500 text-2xl">check_circle</span>
              <div>
                <p class="font-medium text-green-600 dark:text-green-400">All zones synced successfully!</p>
                <p class="text-sm text-surface-600 dark:text-surface-400">Last sync: {{ dnsSyncResult.last_sync }}</p>
              </div>
            </div>
          </div>

          <!-- Note about missing zones -->
          <div v-if="dnsSyncResult.zones_failed?.length > 0" class="p-3 bg-amber-50 dark:bg-amber-500/10 rounded-lg border border-amber-200 dark:border-amber-500/20">
            <div class="flex items-start gap-2">
              <span class="material-symbols-rounded text-amber-500 mt-0.5">info</span>
              <p class="text-sm text-amber-700 dark:text-amber-400">
                Failed zones may not exist on the secondary nameserver (ns2). 
                You may need to manually create them as slave zones on ns2 using:
                <code class="bg-amber-100 dark:bg-amber-900/50 px-1 rounded">pdnsutil create-slave-zone [zone] [primary-ip]</code>
              </p>
            </div>
          </div>
        </template>
      </div>
      
      <template #footer>
        <div class="flex justify-end gap-3">
          <button @click="dnsSyncModal = false" class="btn-secondary">Close</button>
          <button v-if="!dnsSyncing && dnsSyncResult?.success" @click="syncAllDnsZones" class="btn-primary">
            <span class="material-symbols-rounded mr-1">sync</span>
            Sync Again
          </button>
        </div>
      </template>
    </Modal>

    <!-- OLS extprocessor Calculator Modal -->
    <Modal :show="showOlsCalculator" @close="showOlsCalculator = false" size="full">
      <template #title>
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-amber-500">calculate</span>
          extprocessor Configuration Calculator
        </div>
      </template>
      
      <div class="space-y-6">
        <!-- Loading State -->
        <div v-if="olsCalculatorLoading" class="py-12 text-center">
          <div class="inline-block w-8 h-8 border-4 border-amber-500 border-t-transparent rounded-full animate-spin"></div>
          <p class="mt-3 text-surface-500">Analyzing system resources...</p>
        </div>

        <!-- Calculator Results -->
        <template v-else-if="olsCalculatorData">
          <!-- Simulation Mode Banner -->
          <div v-if="olsCalculatorData.is_simulation" class="flex items-center gap-3 p-3 bg-amber-500/10 border border-amber-500/30 rounded-xl">
            <span class="material-symbols-rounded text-amber-500">science</span>
            <span class="text-sm text-amber-600 dark:text-amber-400">
              <strong>Simulation Mode</strong> - Showing calculated values for custom hardware specs
            </span>
          </div>

          <!-- System Specs with Editable Inputs -->
          <div class="grid grid-cols-3 gap-4">
            <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
              <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-rounded text-blue-500">memory</span>
                <span class="text-sm font-medium text-surface-500">CPU Cores</span>
              </div>
              <input 
                v-model.number="olsCustomCores" 
                type="number" 
                min="1" 
                max="128"
                class="w-full text-2xl font-bold bg-transparent border-b-2 border-surface-300 dark:border-surface-600 focus:border-blue-500 outline-none py-1"
              />
              <p v-if="olsCalculatorData.is_simulation" class="text-xs text-surface-400 mt-1">
                Actual: {{ olsCalculatorData.system.actual_cpu_cores }}
              </p>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
              <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-rounded text-green-500">storage</span>
                <span class="text-sm font-medium text-surface-500">Total RAM (GB)</span>
              </div>
              <input 
                v-model.number="olsCustomRamGB" 
                type="number" 
                min="1" 
                max="512"
                step="0.5"
                class="w-full text-2xl font-bold bg-transparent border-b-2 border-surface-300 dark:border-surface-600 focus:border-green-500 outline-none py-1"
              />
              <p class="text-xs text-surface-400 mt-1">
                <template v-if="olsCalculatorData.is_simulation">Actual: {{ Math.round(olsCalculatorData.system.actual_ram_mb / 1024 * 10) / 10 }} GB</template>
                <template v-else>{{ olsCalculatorData.system.available_ram_human }} available</template>
              </p>
            </div>
            <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-4">
              <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-rounded text-purple-500">language</span>
                <span class="text-sm font-medium text-surface-500">Virtual Hosts</span>
              </div>
              <input 
                v-model.number="olsCustomVhosts" 
                type="number" 
                min="0" 
                max="500"
                class="w-full text-2xl font-bold bg-transparent border-b-2 border-surface-300 dark:border-surface-600 focus:border-purple-500 outline-none py-1"
              />
              <p v-if="olsCalculatorData.is_simulation" class="text-xs text-surface-400 mt-1">
                Actual: {{ olsCalculatorData.vhosts.actual_count }}
              </p>
            </div>
          </div>

          <!-- Simulate Button -->
          <div class="flex justify-center">
            <button @click="simulateOlsCalculator" class="btn-primary px-6">
              <span class="material-symbols-rounded">calculate</span>
              Calculate for Custom Specs
            </button>
          </div>

          <!-- Side by Side Comparison -->
          <div class="grid grid-cols-2 gap-6">
            <!-- Current Config -->
            <div class="bg-surface-50 dark:bg-surface-800 rounded-xl p-5">
              <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-rounded text-surface-400">description</span>
                <h4 class="font-semibold">Current Configuration</h4>
              </div>
              <div class="space-y-3 text-sm font-mono">
                <div class="flex justify-between py-1.5 border-b border-surface-200 dark:border-surface-700">
                  <span class="text-surface-500">maxConns</span>
                  <span class="font-medium">{{ olsCalculatorData.current?.maxConns ?? '-' }}</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-surface-200 dark:border-surface-700">
                  <span class="text-surface-500">PHP_LSAPI_CHILDREN</span>
                  <span class="font-medium">{{ olsCalculatorData.current?.PHP_LSAPI_CHILDREN ?? '-' }}</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-surface-200 dark:border-surface-700">
                  <span class="text-surface-500">LSAPI_AVOID_FORK</span>
                  <span class="font-medium">{{ olsCalculatorData.current?.LSAPI_AVOID_FORK ?? '-' }}</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-surface-200 dark:border-surface-700">
                  <span class="text-surface-500">memSoftLimit</span>
                  <span class="font-medium">{{ olsCalculatorData.current?.memSoftLimit ?? '-' }}</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-surface-200 dark:border-surface-700">
                  <span class="text-surface-500">memHardLimit</span>
                  <span class="font-medium">{{ olsCalculatorData.current?.memHardLimit ?? '-' }}</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-surface-200 dark:border-surface-700">
                  <span class="text-surface-500">procSoftLimit</span>
                  <span class="font-medium">{{ olsCalculatorData.current?.procSoftLimit ?? '-' }}</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-surface-200 dark:border-surface-700">
                  <span class="text-surface-500">procHardLimit</span>
                  <span class="font-medium">{{ olsCalculatorData.current?.procHardLimit ?? '-' }}</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-surface-200 dark:border-surface-700">
                  <span class="text-surface-500">initTimeout</span>
                  <span class="font-medium">{{ olsCalculatorData.current?.initTimeout ?? '-' }}</span>
                </div>
                <div class="flex justify-between py-1.5">
                  <span class="text-surface-500">backlog</span>
                  <span class="font-medium">{{ olsCalculatorData.current?.backlog ?? '-' }}</span>
                </div>
              </div>
            </div>

            <!-- Recommended Config -->
            <div class="bg-amber-50 dark:bg-amber-500/10 rounded-xl p-5 ring-2 ring-amber-500/30">
              <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-rounded text-amber-500">auto_fix_high</span>
                <h4 class="font-semibold text-amber-700 dark:text-amber-400">Recommended Configuration</h4>
              </div>
              <div class="space-y-3 text-sm font-mono">
                <div class="flex justify-between py-1.5 border-b border-amber-200 dark:border-amber-500/20">
                  <span class="text-surface-500">maxConns</span>
                  <span class="font-medium" :class="olsCalculatorData.current?.maxConns !== olsCalculatorData.recommended.maxConns ? 'text-amber-600 dark:text-amber-400' : ''">
                    {{ olsCalculatorData.recommended.maxConns }}
                    <span v-if="olsCalculatorData.current?.maxConns !== olsCalculatorData.recommended.maxConns" class="text-xs ml-1 opacity-60">
                      {{ olsCalculatorData.current?.maxConns ? (olsCalculatorData.recommended.maxConns > olsCalculatorData.current.maxConns ? '+' : '') + (olsCalculatorData.recommended.maxConns - olsCalculatorData.current.maxConns) : '' }}
                    </span>
                  </span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-amber-200 dark:border-amber-500/20">
                  <span class="text-surface-500">PHP_LSAPI_CHILDREN</span>
                  <span class="font-medium" :class="olsCalculatorData.current?.PHP_LSAPI_CHILDREN !== olsCalculatorData.recommended.PHP_LSAPI_CHILDREN ? 'text-amber-600 dark:text-amber-400' : ''">
                    {{ olsCalculatorData.recommended.PHP_LSAPI_CHILDREN }}
                    <span v-if="olsCalculatorData.current?.PHP_LSAPI_CHILDREN !== olsCalculatorData.recommended.PHP_LSAPI_CHILDREN" class="text-xs ml-1 opacity-60">
                      {{ olsCalculatorData.current?.PHP_LSAPI_CHILDREN ? (olsCalculatorData.recommended.PHP_LSAPI_CHILDREN > olsCalculatorData.current.PHP_LSAPI_CHILDREN ? '+' : '') + (olsCalculatorData.recommended.PHP_LSAPI_CHILDREN - olsCalculatorData.current.PHP_LSAPI_CHILDREN) : '' }}
                    </span>
                  </span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-amber-200 dark:border-amber-500/20">
                  <span class="text-surface-500">LSAPI_AVOID_FORK</span>
                  <span class="font-medium" :class="olsCalculatorData.current?.LSAPI_AVOID_FORK !== olsCalculatorData.recommended.LSAPI_AVOID_FORK ? 'text-amber-600 dark:text-amber-400' : ''">{{ olsCalculatorData.recommended.LSAPI_AVOID_FORK }}</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-amber-200 dark:border-amber-500/20">
                  <span class="text-surface-500">memSoftLimit</span>
                  <span class="font-medium" :class="olsCalculatorData.current?.memSoftLimit !== olsCalculatorData.recommended.memSoftLimit ? 'text-amber-600 dark:text-amber-400' : ''">{{ olsCalculatorData.recommended.memSoftLimit }}</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-amber-200 dark:border-amber-500/20">
                  <span class="text-surface-500">memHardLimit</span>
                  <span class="font-medium" :class="olsCalculatorData.current?.memHardLimit !== olsCalculatorData.recommended.memHardLimit ? 'text-amber-600 dark:text-amber-400' : ''">{{ olsCalculatorData.recommended.memHardLimit }}</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-amber-200 dark:border-amber-500/20">
                  <span class="text-surface-500">procSoftLimit</span>
                  <span class="font-medium" :class="olsCalculatorData.current?.procSoftLimit !== olsCalculatorData.recommended.procSoftLimit ? 'text-amber-600 dark:text-amber-400' : ''">
                    {{ olsCalculatorData.recommended.procSoftLimit }}
                    <span v-if="olsCalculatorData.current?.procSoftLimit !== olsCalculatorData.recommended.procSoftLimit" class="text-xs ml-1 opacity-60">
                      {{ olsCalculatorData.current?.procSoftLimit ? (olsCalculatorData.recommended.procSoftLimit > olsCalculatorData.current.procSoftLimit ? '+' : '') + (olsCalculatorData.recommended.procSoftLimit - olsCalculatorData.current.procSoftLimit) : '' }}
                    </span>
                  </span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-amber-200 dark:border-amber-500/20">
                  <span class="text-surface-500">procHardLimit</span>
                  <span class="font-medium" :class="olsCalculatorData.current?.procHardLimit !== olsCalculatorData.recommended.procHardLimit ? 'text-amber-600 dark:text-amber-400' : ''">
                    {{ olsCalculatorData.recommended.procHardLimit }}
                    <span v-if="olsCalculatorData.current?.procHardLimit !== olsCalculatorData.recommended.procHardLimit" class="text-xs ml-1 opacity-60">
                      {{ olsCalculatorData.current?.procHardLimit ? (olsCalculatorData.recommended.procHardLimit > olsCalculatorData.current.procHardLimit ? '+' : '') + (olsCalculatorData.recommended.procHardLimit - olsCalculatorData.current.procHardLimit) : '' }}
                    </span>
                  </span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-amber-200 dark:border-amber-500/20">
                  <span class="text-surface-500">initTimeout</span>
                  <span class="font-medium" :class="olsCalculatorData.current?.initTimeout !== olsCalculatorData.recommended.initTimeout ? 'text-amber-600 dark:text-amber-400' : ''">{{ olsCalculatorData.recommended.initTimeout }}</span>
                </div>
                <div class="flex justify-between py-1.5">
                  <span class="text-surface-500">backlog</span>
                  <span class="font-medium" :class="olsCalculatorData.current?.backlog !== olsCalculatorData.recommended.backlog ? 'text-amber-600 dark:text-amber-400' : ''">
                    {{ olsCalculatorData.recommended.backlog }}
                    <span v-if="olsCalculatorData.current?.backlog !== olsCalculatorData.recommended.backlog" class="text-xs ml-1 opacity-60">
                      {{ olsCalculatorData.current?.backlog ? (olsCalculatorData.recommended.backlog > olsCalculatorData.current.backlog ? '+' : '') + (olsCalculatorData.recommended.backlog - olsCalculatorData.current.backlog) : '' }}
                    </span>
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- Generated Config Block -->
          <div>
            <div class="flex items-center justify-between mb-3">
              <h4 class="font-semibold flex items-center gap-2">
                <span class="material-symbols-rounded text-amber-500">code</span>
                Generated Configuration
              </h4>
              <button @click="copyCalculatorConfig" class="btn-secondary btn-sm" :class="olsCalculatorCopied && 'bg-green-500/10 text-green-500'">
                <span class="material-symbols-rounded">{{ olsCalculatorCopied ? 'check' : 'content_copy' }}</span>
                {{ olsCalculatorCopied ? 'Copied' : 'Copy' }}
              </button>
            </div>
            <pre class="bg-surface-900 text-surface-100 p-4 rounded-xl text-sm font-mono overflow-x-auto whitespace-pre">{{ olsCalculatorData.config_template }}</pre>
          </div>

          <!-- Info Note -->
          <div class="flex gap-3 p-4 bg-blue-50 dark:bg-blue-500/10 rounded-xl text-sm">
            <span class="material-symbols-rounded text-blue-500 flex-shrink-0">info</span>
            <div class="text-surface-600 dark:text-surface-300">
              <p class="font-medium mb-1">How to Apply</p>
              <p>Copy the configuration above and paste it into your OpenLiteSpeed httpd_config.conf file, replacing the existing extprocessor lsphp block. Then restart OpenLiteSpeed to apply changes.</p>
            </div>
          </div>
        </template>
      </div>

      <template #footer>
        <div class="flex justify-end gap-3">
          <button @click="showOlsCalculator = false" class="btn-secondary">Close</button>
          <button v-if="olsCalculatorData" @click="openOlsCalculator" class="btn-secondary">
            <span class="material-symbols-rounded">refresh</span>
            Refresh
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>

<style scoped>
/* Logs AI Panel Styles */
.logs-ai-content :deep(p) {
  margin: 0 0 8px 0;
}

.logs-ai-content :deep(p:last-child) {
  margin-bottom: 0;
}

.logs-ai-content :deep(code) {
  background: #070b14;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 12px;
  color: #22d3ee;
}

.logs-ai-content :deep(pre) {
  background: #050810;
  padding: 12px;
  border-radius: 8px;
  overflow-x: auto;
  margin: 8px 0;
  border: 1px solid #1e293b;
}

.logs-ai-content :deep(pre code) {
  background: transparent;
  padding: 0;
  color: #4ade80;
}

.logs-ai-content :deep(.command-block) {
  position: relative;
  background: #0a1628;
  border: 1px solid #22d3ee40;
  border-radius: 8px;
  padding: 12px;
  margin: 8px 0;
}

.logs-ai-content :deep(.command-block code) {
  background: transparent;
  color: #4ade80;
  display: block;
  white-space: pre-wrap;
  word-break: break-all;
}

.logs-ai-content :deep(.copy-cmd-btn) {
  position: absolute;
  top: 8px;
  right: 8px;
  background: #6366f1;
  color: white;
  border: none;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 10px;
  cursor: pointer;
  transition: background 0.2s;
}

.logs-ai-content :deep(.copy-cmd-btn:hover) {
  background: #4f46e5;
}

.logs-ai-content :deep(.ai-log-section) {
  display: flex;
  align-items: center;
  gap: 6px;
  margin: 12px 0 6px 0;
  padding-bottom: 4px;
  border-bottom: 1px solid #334155;
  font-size: 12px;
  font-weight: 600;
}

.logs-ai-content :deep(.ai-log-section .material-symbols-rounded) {
  font-size: 16px;
}

.logs-ai-content :deep(.ai-log-section.cause) {
  color: #fbbf24;
  border-color: #fbbf2440;
}

.logs-ai-content :deep(.ai-log-section.fix),
.logs-ai-content :deep(.ai-log-section.command) {
  color: #4ade80;
  border-color: #4ade8040;
}

.logs-ai-content :deep(ul),
.logs-ai-content :deep(ol) {
  margin: 8px 0;
  padding-left: 20px;
}

.logs-ai-content :deep(li) {
  margin: 4px 0;
}
</style>
