<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import ConfirmModal from '@/components/ConfirmModal.vue'

const toast = useToastStore()

const loading = ref(true)
const refreshing = ref(false)
const autoRefresh = ref(true)
const refreshInterval = ref(null)
const showRestartModal = ref(false)

// Diagnostic data
const diagnostics = ref({
  agent: null,
  socket: null,
  token: null,
  php: null,
  mysql: null,
  permissions: null,
  security: null,
  logs: [],
  errors: [],
  timestamp: null
})

// System journal logs (when agent crashed)
const systemLogs = ref(null)
const systemLogsLoading = ref(false)
const systemLogsExpanded = ref(true)

// Overall health status
const healthStatus = computed(() => {
  if (!diagnostics.value.agent) return 'unknown'
  
  const checks = [
    diagnostics.value.agent?.running,
    diagnostics.value.socket?.exists,
    diagnostics.value.token?.exists,
    diagnostics.value.mysql?.connected,
    diagnostics.value.permissions?.correct
  ]
  
  const passed = checks.filter(Boolean).length
  const total = checks.length
  
  if (passed === total) return 'healthy'
  if (passed >= total - 1) return 'warning'
  return 'critical'
})

const healthColor = computed(() => {
  switch (healthStatus.value) {
    case 'healthy': return 'text-green-500'
    case 'warning': return 'text-amber-500'
    case 'critical': return 'text-red-500'
    default: return 'text-surface-400'
  }
})

const healthIcon = computed(() => {
  switch (healthStatus.value) {
    case 'healthy': return 'check_circle'
    case 'warning': return 'warning'
    case 'critical': return 'error'
    default: return 'help'
  }
})

const healthLabel = computed(() => {
  switch (healthStatus.value) {
    case 'healthy': return 'All Systems Operational'
    case 'warning': return 'Minor Issues Detected'
    case 'critical': return 'Critical Issues Found'
    default: return 'Status Unknown'
  }
})

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

// Check if agent is crashed/stopped
const isAgentCrashed = computed(() => {
  return diagnostics.value.agent && !diagnostics.value.agent.running
})

// Fetch system journal logs
const fetchSystemLogs = async () => {
  systemLogsLoading.value = true
  try {
    const response = await api.get('/services/vpsadmin-agent/logs?lines=100')
    if (response.data.success) {
      systemLogs.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch system logs', e)
    // If even this fails, show manual commands
    systemLogs.value = { logs: '', errors: 'Could not fetch logs - agent may be completely down' }
  } finally {
    systemLogsLoading.value = false
  }
}

// Fetch diagnostics
const fetchDiagnostics = async (showToast = false) => {
  if (refreshing.value) return
  
  refreshing.value = true
  try {
    const response = await api.get('/agent/diagnostics')
    if (response.data.success) {
      diagnostics.value = response.data.data
      if (showToast) {
        toast.success('Diagnostics refreshed')
      }
    } else {
      toast.error(response.data.error || 'Failed to load diagnostics')
    }
  } catch (e) {
    console.error('Failed to load diagnostics', e)
    // Set error state
    diagnostics.value = {
      agent: { running: false, error: e.response?.data?.error || 'Cannot connect to API' },
      socket: { exists: false },
      token: { exists: false },
      php: { extensions: [] },
      mysql: { connected: false },
      permissions: { correct: false },
      security: { cpguard: null, modsec: null },
      logs: [],
      errors: [e.response?.data?.error || 'Failed to connect to API'],
      timestamp: new Date().toISOString()
    }
    if (showToast) {
      toast.error('Failed to connect to API')
    }
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

// Restart agent
const restartAgent = async () => {
  showRestartModal.value = false
  refreshing.value = true
  try {
    const response = await api.post('/agent/restart')
    if (response.data.success) {
      toast.success('Agent restart initiated')
      // Wait a moment then refresh
      setTimeout(() => fetchDiagnostics(true), 3000)
    } else {
      toast.error(response.data.error || 'Failed to restart agent')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restart agent')
  } finally {
    refreshing.value = false
  }
}

// Open restart confirmation
const confirmRestart = () => {
  showRestartModal.value = true
}

// Toggle auto-refresh
const toggleAutoRefresh = () => {
  autoRefresh.value = !autoRefresh.value
  if (autoRefresh.value) {
    startAutoRefresh()
  } else {
    stopAutoRefresh()
  }
}

const startAutoRefresh = () => {
  if (refreshInterval.value) return
  refreshInterval.value = setInterval(() => {
    fetchDiagnostics()
  }, 30000) // Every 30 seconds
}

const stopAutoRefresh = () => {
  if (refreshInterval.value) {
    clearInterval(refreshInterval.value)
    refreshInterval.value = null
  }
}

// Format bytes
const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  let i = 0
  while (bytes >= 1024 && i < units.length - 1) {
    bytes /= 1024
    i++
  }
  return `${bytes.toFixed(1)} ${units[i]}`
}

// Format uptime
const formatUptime = (seconds) => {
  if (!seconds) return '-'
  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  const mins = Math.floor((seconds % 3600) / 60)
  
  if (days > 0) return `${days}d ${hours}h ${mins}m`
  if (hours > 0) return `${hours}h ${mins}m`
  return `${mins}m`
}

// Get log level class
const getLogLevelClass = (level) => {
  switch (level?.toLowerCase()) {
    case 'error':
    case 'fatal':
      return 'text-red-500'
    case 'warning':
    case 'warn':
      return 'text-amber-500'
    case 'info':
    case 'notice':
      return 'text-blue-500'
    default:
      return 'text-surface-400'
  }
}

// Auto-fetch system logs when agent is detected as crashed
watch(isAgentCrashed, (crashed) => {
  if (crashed && !systemLogs.value) {
    fetchSystemLogs()
  }
}, { immediate: true })

onMounted(() => {
  fetchDiagnostics()
  startAutoRefresh()
})

onUnmounted(() => {
  stopAutoRefresh()
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="page-header">
      <div>
        <h1 class="page-title">Agent Health Monitor</h1>
        <p class="text-surface-500 text-sm mt-1">System diagnostics and agent status</p>
      </div>
      <div class="flex flex-wrap items-center gap-2 sm:gap-3">
        <button 
          @click="toggleAutoRefresh" 
          :class="['btn-ghost btn-sm', autoRefresh ? 'text-green-500' : 'text-surface-400']"
          :title="autoRefresh ? 'Auto-refresh ON (30s)' : 'Auto-refresh OFF'"
        >
          <span class="material-symbols-rounded">{{ autoRefresh ? 'sync' : 'sync_disabled' }}</span>
        </button>
        <button 
          @click="fetchDiagnostics(true)" 
          class="btn-secondary"
          :disabled="refreshing"
        >
          <span class="material-symbols-rounded" :class="{ 'animate-spin': refreshing }">refresh</span>
          <span class="hidden sm:inline">Refresh</span>
        </button>
        <button 
          @click="confirmRestart" 
          class="btn-primary"
          :disabled="refreshing"
        >
          <span class="material-symbols-rounded">restart_alt</span>
          <span class="hidden sm:inline">Restart</span>
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner"></span>
    </div>

    <template v-else>
      <!-- Overall Status Card -->
      <div class="card p-6 mb-6">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-4">
            <div :class="[
              'w-16 h-16 rounded-2xl flex items-center justify-center',
              healthStatus === 'healthy' ? 'bg-green-100 dark:bg-green-500/20' :
              healthStatus === 'warning' ? 'bg-amber-100 dark:bg-amber-500/20' :
              healthStatus === 'critical' ? 'bg-red-100 dark:bg-red-500/20' :
              'bg-surface-100 dark:bg-surface-800'
            ]">
              <span :class="['material-symbols-rounded text-4xl', healthColor]">{{ healthIcon }}</span>
            </div>
            <div>
              <h2 class="text-2xl font-bold" :class="healthColor">{{ healthLabel }}</h2>
              <p class="text-surface-500 text-sm mt-1">
                Last checked: {{ diagnostics.timestamp ? new Date(diagnostics.timestamp).toLocaleString() : 'Never' }}
              </p>
            </div>
          </div>
          <div v-if="diagnostics.agent?.uptime" class="text-right">
            <p class="text-sm text-surface-500">Agent Uptime</p>
            <p class="text-2xl font-bold">{{ formatUptime(diagnostics.agent.uptime) }}</p>
          </div>
        </div>
      </div>

      <!-- Agent Crash Logs (Auto-displayed when crashed) -->
      <div v-if="isAgentCrashed" class="card border-l-4 border-red-500 bg-red-50 dark:bg-red-900/20 mb-6">
        <div class="p-4">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-red-100 dark:bg-red-500/20 flex items-center justify-center">
                <span class="material-symbols-rounded text-red-600 dark:text-red-400 text-xl">error</span>
              </div>
              <div>
                <h3 class="font-semibold text-red-800 dark:text-red-300">Agent Service Crashed</h3>
                <p class="text-sm text-red-600 dark:text-red-400">Check the system logs below to diagnose the issue</p>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <button 
                @click="fetchSystemLogs" 
                class="btn-secondary btn-sm"
                :disabled="systemLogsLoading"
              >
                <span v-if="systemLogsLoading" class="spinner"></span>
                <span v-else class="material-symbols-rounded">refresh</span>
                Refresh Logs
              </button>
              <button 
                @click="systemLogsExpanded = !systemLogsExpanded" 
                class="btn-ghost btn-sm"
              >
                <span class="material-symbols-rounded">{{ systemLogsExpanded ? 'expand_less' : 'expand_more' }}</span>
              </button>
            </div>
          </div>
          
          <div v-if="systemLogsExpanded" class="space-y-3">
            <!-- Error Summary -->
            <div v-if="systemLogs?.errors" class="bg-red-100 dark:bg-red-900/30 rounded-lg p-3">
              <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-rounded text-red-600 text-lg">warning</span>
                <span class="font-medium text-red-800 dark:text-red-300 text-sm">Recent Errors (journalctl -p err)</span>
              </div>
              <pre class="text-xs text-red-700 dark:text-red-300 whitespace-pre-wrap font-mono max-h-40 overflow-auto">{{ systemLogs.errors }}</pre>
            </div>
            
            <!-- Full System Logs -->
            <div class="bg-surface-900 dark:bg-surface-950 rounded-lg p-3">
              <div class="flex items-center justify-between mb-2">
                <span class="text-surface-400 text-sm">System Journal (last {{ systemLogs?.lines || 100 }} lines)</span>
                <button 
                  @click="copyToClipboard(systemLogs?.logs || '', 'Logs copied to clipboard')"
                  class="text-surface-400 hover:text-white text-xs flex items-center gap-1"
                >
                  <span class="material-symbols-rounded text-sm">content_copy</span>
                  Copy
                </button>
              </div>
              <pre v-if="systemLogs?.logs" class="text-xs text-green-400 whitespace-pre-wrap font-mono max-h-80 overflow-auto">{{ systemLogs.logs }}</pre>
              <div v-else-if="systemLogsLoading" class="flex items-center justify-center py-6">
                <span class="spinner"></span>
              </div>
              <div v-else-if="systemLogs?.permission_error" class="text-amber-400 text-sm py-4">
                <p class="mb-2">Cannot read logs - permission not granted yet.</p>
                <p class="text-xs text-surface-500">Run the command shown above in SSH, then click "Refresh Logs"</p>
              </div>
              <p v-else-if="systemLogs" class="text-surface-500 text-sm py-4">No logs available</p>
              <p v-else class="text-surface-500 text-sm py-4">Click "Refresh Logs" to load</p>
            </div>
            
            <!-- Quick Fix Commands -->
            <div class="bg-surface-100 dark:bg-surface-800 rounded-lg p-3">
              <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-rounded text-surface-500 text-lg">terminal</span>
                <span class="font-medium text-sm">Quick Fix Commands (SSH)</span>
              </div>
              <div class="space-y-2">
                <div class="flex items-center gap-2">
                  <code class="text-xs bg-surface-200 dark:bg-surface-700 px-2 py-1 rounded font-mono flex-1">php -l /var/www/vps-admin/agent/agent.php</code>
                  <button 
                    @click="copyToClipboard('php -l /var/www/vps-admin/agent/agent.php')"
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
                <div class="flex items-center gap-2">
                  <code class="text-xs bg-surface-200 dark:bg-surface-700 px-2 py-1 rounded font-mono flex-1">journalctl -u vpsadmin-agent -n 100 --no-pager</code>
                  <button 
                    @click="copyToClipboard('journalctl -u vpsadmin-agent -n 100 --no-pager')"
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

      <!-- Quick Stats Grid -->
      <div class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 mb-6">
        <!-- Agent Status -->
        <div class="card p-4">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-surface-500">Agent Service</span>
            <span :class="[
              'w-3 h-3 rounded-full',
              diagnostics.agent?.running ? 'bg-green-500' : 'bg-red-500'
            ]"></span>
          </div>
          <p class="font-semibold">{{ diagnostics.agent?.running ? 'Running' : 'Stopped' }}</p>
          <p class="text-xs text-surface-400 mt-1">PID: {{ diagnostics.agent?.pid || '-' }}</p>
        </div>

        <!-- Socket Status -->
        <div class="card p-4">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-surface-500">Socket</span>
            <span :class="[
              'w-3 h-3 rounded-full',
              diagnostics.socket?.exists ? 'bg-green-500' : 'bg-red-500'
            ]"></span>
          </div>
          <p class="font-semibold">{{ diagnostics.socket?.exists ? 'Connected' : 'Not Found' }}</p>
          <p class="text-xs text-surface-400 mt-1 truncate" :title="diagnostics.socket?.path">
            {{ diagnostics.socket?.path || '-' }}
          </p>
        </div>

        <!-- MySQL Status -->
        <div class="card p-4">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-surface-500">MySQL</span>
            <span :class="[
              'w-3 h-3 rounded-full',
              diagnostics.mysql?.connected ? 'bg-green-500' : 'bg-red-500'
            ]"></span>
          </div>
          <p class="font-semibold">{{ diagnostics.mysql?.connected ? 'Connected' : 'Disconnected' }}</p>
          <p class="text-xs text-surface-400 mt-1">{{ diagnostics.mysql?.version || '-' }}</p>
        </div>

        <!-- Memory Usage -->
        <div class="card p-4">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm text-surface-500">Memory Usage</span>
            <span class="material-symbols-rounded text-surface-400 text-sm">memory</span>
          </div>
          <p class="font-semibold">{{ formatBytes(diagnostics.agent?.memory) }}</p>
          <p class="text-xs text-surface-400 mt-1">Agent process</p>
        </div>
      </div>

      <!-- Detailed Checks Grid -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6 mb-6">
        <!-- Agent Details -->
        <div class="card p-6">
          <h3 class="font-semibold mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">smart_toy</span>
            Agent Details
          </h3>
          <div class="space-y-3">
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">Status</span>
              <span :class="diagnostics.agent?.running ? 'text-green-500' : 'text-red-500'" class="flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">{{ diagnostics.agent?.running ? 'check_circle' : 'cancel' }}</span>
                {{ diagnostics.agent?.running ? 'Running' : 'Stopped' }}
              </span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">Enabled on Boot</span>
              <span :class="diagnostics.agent?.enabled ? 'text-green-500' : 'text-amber-500'" class="flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">{{ diagnostics.agent?.enabled ? 'check_circle' : 'warning' }}</span>
                {{ diagnostics.agent?.enabled ? 'Yes' : 'No' }}
              </span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">Process ID</span>
              <span class="font-mono">{{ diagnostics.agent?.pid || '-' }}</span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">Uptime</span>
              <span>{{ diagnostics.agent?.uptime_human || formatUptime(diagnostics.agent?.uptime) }}</span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">Memory Usage</span>
              <span>{{ diagnostics.agent?.memory_human || formatBytes(diagnostics.agent?.memory) }}</span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">CPU Time</span>
              <span>{{ diagnostics.agent?.cpu_time || '-' }}</span>
            </div>
            <div v-if="diagnostics.agent?.started_at" class="flex justify-between items-center py-2">
              <span class="text-surface-500">Started At</span>
              <span class="text-sm">{{ diagnostics.agent?.started_at }}</span>
            </div>
          </div>
        </div>

        <!-- Socket & Token -->
        <div class="card p-6">
          <h3 class="font-semibold mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-purple-500">cable</span>
            Socket & Authentication
          </h3>
          <div class="space-y-3">
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">Socket Exists</span>
              <span :class="diagnostics.socket?.exists ? 'text-green-500' : 'text-red-500'" class="flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">{{ diagnostics.socket?.exists ? 'check_circle' : 'cancel' }}</span>
                {{ diagnostics.socket?.exists ? 'Yes' : 'No' }}
              </span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">Socket Path</span>
              <span class="font-mono text-sm truncate max-w-[200px]" :title="diagnostics.socket?.path">
                {{ diagnostics.socket?.path || '-' }}
              </span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">Socket Permissions</span>
              <span :class="diagnostics.socket?.permissions_ok ? 'text-green-500' : 'text-red-500'">
                {{ diagnostics.socket?.permissions || '-' }}
              </span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">Token File</span>
              <span :class="diagnostics.token?.exists ? 'text-green-500' : 'text-red-500'" class="flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">{{ diagnostics.token?.exists ? 'check_circle' : 'cancel' }}</span>
                {{ diagnostics.token?.exists ? 'Found' : 'Missing' }}
              </span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">Token Length</span>
              <span>{{ diagnostics.token?.length || 0 }} characters</span>
            </div>
            <div class="flex justify-between items-center py-2">
              <span class="text-surface-500">Token Permissions</span>
              <span :class="diagnostics.token?.permissions_ok ? 'text-green-500' : 'text-amber-500'">
                {{ diagnostics.token?.permissions || '-' }}
              </span>
            </div>
          </div>
        </div>

        <!-- PHP Extensions -->
        <div class="card p-6">
          <h3 class="font-semibold mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-blue-500">extension</span>
            PHP Extensions
          </h3>
          <div class="grid grid-cols-2 gap-2">
            <div 
              v-for="ext in ['sockets', 'pcntl', 'posix', 'json', 'pdo_mysql', 'openssl', 'mbstring', 'curl']" 
              :key="ext"
              class="flex items-center gap-2 p-2 rounded-lg bg-surface-50 dark:bg-surface-800"
            >
              <span 
                :class="[
                  'material-symbols-rounded text-sm',
                  diagnostics.php?.extensions?.includes(ext) ? 'text-green-500' : 'text-red-500'
                ]"
              >
                {{ diagnostics.php?.extensions?.includes(ext) ? 'check_circle' : 'cancel' }}
              </span>
              <span class="font-mono text-sm">{{ ext }}</span>
            </div>
          </div>
          <div class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700">
            <div class="flex justify-between text-sm">
              <span class="text-surface-500">PHP Version</span>
              <span>{{ diagnostics.php?.version || '-' }}</span>
            </div>
          </div>
        </div>

        <!-- Security Status -->
        <div class="card p-6">
          <h3 class="font-semibold mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-amber-500">security</span>
            Security & Firewall
          </h3>
          <div class="space-y-3">
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">CPGuard Status</span>
              <span :class="diagnostics.security?.cpguard === 'blocking' ? 'text-red-500' : 'text-green-500'" class="flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">
                  {{ diagnostics.security?.cpguard === 'blocking' ? 'block' : 'check_circle' }}
                </span>
                {{ diagnostics.security?.cpguard || 'OK' }}
              </span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">ModSecurity</span>
              <span :class="diagnostics.security?.modsec_blocking ? 'text-amber-500' : 'text-green-500'" class="flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">
                  {{ diagnostics.security?.modsec_blocking ? 'warning' : 'check_circle' }}
                </span>
                {{ diagnostics.security?.modsec_blocking ? 'Active Blocks' : 'OK' }}
              </span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">Fail2ban</span>
              <span :class="diagnostics.security?.fail2ban ? 'text-green-500' : 'text-surface-400'">
                {{ diagnostics.security?.fail2ban ? 'Active' : 'Not Checked' }}
              </span>
            </div>
            <div class="flex justify-between items-center py-2">
              <span class="text-surface-500">File Permissions</span>
              <span :class="diagnostics.permissions?.correct ? 'text-green-500' : 'text-red-500'" class="flex items-center gap-1">
                <span class="material-symbols-rounded text-sm">
                  {{ diagnostics.permissions?.correct ? 'check_circle' : 'cancel' }}
                </span>
                {{ diagnostics.permissions?.correct ? 'Correct' : 'Issues Found' }}
              </span>
            </div>
          </div>
          
          <!-- Permission Issues -->
          <div v-if="diagnostics.permissions?.issues?.length" class="mt-4 p-3 bg-red-50 dark:bg-red-500/10 rounded-xl border border-red-200 dark:border-red-500/30">
            <p class="text-sm font-medium text-red-700 dark:text-red-300 mb-2">Permission Issues:</p>
            <ul class="text-sm text-red-600 dark:text-red-400 space-y-1">
              <li v-for="issue in diagnostics.permissions.issues" :key="issue">{{ issue }}</li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Agent Subsystems -->
      <div v-if="diagnostics.subsystems?.length" class="card p-6 mb-6">
        <h3 class="font-semibold mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded text-indigo-500">widgets</span>
          Agent Subsystems
          <span class="text-xs px-2 py-0.5 rounded-full bg-surface-100 dark:bg-surface-700 text-surface-500 ml-2">
            {{ diagnostics.subsystems?.length }} handlers
          </span>
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4">
          <div 
            v-for="sub in diagnostics.subsystems" 
            :key="sub.class"
            class="p-4 rounded-xl border transition-colors"
            :class="[
              sub.status === 'ok' 
                ? 'bg-surface-50 dark:bg-surface-800/50 border-surface-200 dark:border-surface-700' 
                : sub.status === 'warning'
                  ? 'bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/30'
                  : 'bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-500/30'
            ]"
          >
            <div class="flex items-start justify-between mb-2">
              <div class="flex items-center gap-2">
                <span 
                  class="material-symbols-rounded text-lg"
                  :class="[
                    sub.status === 'ok' ? 'text-primary-500' : 
                    sub.status === 'warning' ? 'text-amber-500' : 'text-red-500'
                  ]"
                >
                  {{ sub.icon }}
                </span>
                <span class="font-semibold">{{ sub.name }}</span>
              </div>
              <span 
                class="w-2.5 h-2.5 rounded-full"
                :class="[
                  sub.status === 'ok' ? 'bg-green-500' : 
                  sub.status === 'warning' ? 'bg-amber-500' : 'bg-red-500'
                ]"
              ></span>
            </div>
            
            <p class="text-xs text-surface-500 mb-3">{{ sub.description }}</p>
            
            <div class="space-y-1.5 text-xs">
              <div class="flex items-center justify-between">
                <span class="text-surface-400">Namespace</span>
                <code class="px-1.5 py-0.5 rounded bg-surface-200 dark:bg-surface-700 font-mono">{{ sub.namespace }}</code>
              </div>
              
              <div class="flex flex-col gap-1">
                <div class="flex items-center justify-between">
                  <span class="text-surface-400">Handler File</span>
                  <span :class="sub.file_exists ? 'text-green-500' : 'text-red-500'" class="flex items-center gap-1">
                    <span class="material-symbols-rounded text-xs">{{ sub.file_exists ? 'check_circle' : 'cancel' }}</span>
                    {{ sub.file_exists ? 'Loaded' : 'Missing' }}
                  </span>
                </div>
                <code class="text-[10px] text-surface-500 font-mono truncate block" :title="sub.file_path">
                  {{ sub.file_path }}
                </code>
              </div>
              
              <div v-if="sub.service" class="flex items-center justify-between">
                <span class="text-surface-400">Service</span>
                <span 
                  :class="sub.service_running ? 'text-green-500' : 'text-red-500'" 
                  class="flex items-center gap-1"
                >
                  <span class="material-symbols-rounded text-xs">{{ sub.service_running ? 'check_circle' : 'cancel' }}</span>
                  {{ sub.service }} ({{ sub.service_status }})
                </span>
              </div>
              
              <div v-if="Object.keys(sub.config_paths || {}).length" class="pt-1.5 border-t border-surface-200 dark:border-surface-700">
                <span class="text-surface-400 block mb-1">Config Files</span>
                <div v-for="(exists, path) in sub.config_paths" :key="path" class="flex items-center gap-1">
                  <span 
                    class="material-symbols-rounded text-xs"
                    :class="exists ? 'text-green-500' : 'text-red-500'"
                  >
                    {{ exists ? 'check_circle' : 'cancel' }}
                  </span>
                  <span class="font-mono text-surface-500 truncate" :title="path">{{ path.split('/').pop() }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Errors Section -->
      <div v-if="diagnostics.errors?.length" class="card p-6 mb-6 border-red-200 dark:border-red-500/30">
        <h3 class="font-semibold mb-4 flex items-center gap-2 text-red-600">
          <span class="material-symbols-rounded">error</span>
          Errors Detected ({{ diagnostics.errors.length }})
        </h3>
        <div class="space-y-2">
          <div 
            v-for="(error, idx) in diagnostics.errors" 
            :key="idx"
            class="p-3 bg-red-50 dark:bg-red-500/10 rounded-xl border border-red-200 dark:border-red-500/30"
          >
            <p class="text-sm text-red-700 dark:text-red-300 font-mono">{{ error }}</p>
          </div>
        </div>
      </div>

      <!-- Recent Logs -->
      <div class="card p-6">
        <h3 class="font-semibold mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded text-surface-500">article</span>
          Recent Agent Logs
        </h3>
        <div v-if="diagnostics.logs?.length" class="bg-surface-900 dark:bg-surface-950 rounded-xl overflow-hidden">
          <div class="p-4 max-h-[400px] overflow-y-auto">
            <div 
              v-for="(log, idx) in diagnostics.logs" 
              :key="idx"
              class="font-mono text-xs leading-6 hover:bg-surface-800 px-2 rounded"
              :class="getLogLevelClass(log.level)"
            >
              <span class="text-surface-500">{{ log.timestamp }}</span>
              <span class="mx-2">|</span>
              <span :class="getLogLevelClass(log.level)">{{ log.level?.toUpperCase() || 'INFO' }}</span>
              <span class="mx-2">|</span>
              <span class="text-surface-300">{{ log.message }}</span>
            </div>
          </div>
        </div>
        <div v-else class="text-center py-8 text-surface-400">
          <span class="material-symbols-rounded text-3xl mb-2 block">article</span>
          No recent logs
        </div>
      </div>

      <!-- Troubleshooting Commands -->
      <div class="card p-6 mt-6">
        <h3 class="font-semibold mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded text-cyan-500">terminal</span>
          Quick Troubleshooting Commands
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
          <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
            <p class="text-sm text-surface-500 mb-1">Check agent status</p>
            <code class="text-sm font-mono">systemctl status vpsadmin-agent</code>
          </div>
          <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
            <p class="text-sm text-surface-500 mb-1">Restart agent</p>
            <code class="text-sm font-mono">systemctl restart vpsadmin-agent</code>
          </div>
          <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
            <p class="text-sm text-surface-500 mb-1">View live logs</p>
            <code class="text-sm font-mono">journalctl -u vpsadmin-agent -f</code>
          </div>
          <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
            <p class="text-sm text-surface-500 mb-1">Check socket</p>
            <code class="text-sm font-mono">ls -la /run/vps-admin/agent.sock</code>
          </div>
          <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
            <p class="text-sm text-surface-500 mb-1">Check recent errors</p>
            <code class="text-sm font-mono">journalctl -u vpsadmin-agent -p err -n 50</code>
          </div>
          <div class="p-3 bg-surface-50 dark:bg-surface-800 rounded-xl">
            <p class="text-sm text-surface-500 mb-1">Test MySQL connection</p>
            <code class="text-sm font-mono">mysql -e "SELECT 1"</code>
          </div>
        </div>
      </div>
    </template>

    <!-- Restart Confirmation Modal -->
    <ConfirmModal
      :show="showRestartModal"
      title="Restart Agent"
      message="Are you sure you want to restart the VPS Admin Agent? This will briefly interrupt all API operations."
      confirm-text="Restart"
      :danger="false"
      :loading="refreshing"
      @confirm="restartAgent"
      @cancel="showRestartModal = false"
    />
  </div>
</template>

