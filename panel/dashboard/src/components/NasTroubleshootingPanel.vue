<script setup>
import { ref, computed } from 'vue'
import api from '@/services/api'

const props = defineProps({
  connections: { type: Array, default: () => [] },
  vpnConnections: { type: Array, default: () => [] },
})

const expandedSection = ref(null)
const diagnosticsExpanded = ref(false)
const diagnosticsRunning = ref(false)
const diagnosticsResults = ref(null)
const diagnosticsError = ref('')
const selectedNas = ref('')
const selectedVpn = ref('')
const customNasIp = ref('')
const customMountPoint = ref('')

function toggleSection(id) {
  expandedSection.value = expandedSection.value === id ? null : id
}

const selectedNasConnection = computed(() => {
  if (!selectedNas.value) return null
  return props.connections.find(c => String(c.id) === String(selectedNas.value))
})

const autoDetectedVpn = computed(() => {
  const nas = selectedNasConnection.value
  if (!nas) return ''
  if (nas.vpn_enabled && nas.vpn_config_path) {
    const match = nas.vpn_config_path.match(/\/([^/]+)\.conf$/)
    return match ? match[1] : nas.vpn_config_path
  }
  return ''
})

const effectiveVpn = computed(() => selectedVpn.value || autoDetectedVpn.value)

const statusCounts = computed(() => {
  if (!diagnosticsResults.value?.checks) return { ok: 0, warning: 0, error: 0, skipped: 0 }
  const checks = Object.values(diagnosticsResults.value.checks)
  return {
    ok: checks.filter(c => c.status === 'ok').length,
    warning: checks.filter(c => c.status === 'warning').length,
    error: checks.filter(c => c.status === 'error').length,
    skipped: checks.filter(c => c.status === 'skipped').length,
  }
})

const sortedChecks = computed(() => {
  if (!diagnosticsResults.value?.checks) return []
  const order = { error: 0, warning: 1, ok: 2, skipped: 3 }
  return Object.entries(diagnosticsResults.value.checks)
    .map(([key, val]) => ({ key, ...val }))
    .sort((a, b) => (order[a.status] ?? 9) - (order[b.status] ?? 9))
})

async function runDiagnostics() {
  diagnosticsRunning.value = true
  diagnosticsError.value = ''
  diagnosticsResults.value = null

  try {
    const params = new URLSearchParams()
    
    if (selectedNas.value) {
      params.append('nas_id', selectedNas.value)
    }
    if (effectiveVpn.value) {
      params.append('vpn_name', effectiveVpn.value)
    }
    if (customNasIp.value) {
      params.append('nas_ip', customNasIp.value)
    }
    if (customMountPoint.value) {
      params.append('mount_point', customMountPoint.value)
    }
    
    const response = await api.get(`/nas/diagnostics?${params.toString()}`)
    
    if (response.data.success) {
      diagnosticsResults.value = response.data.data
    } else {
      diagnosticsError.value = response.data.error || 'Diagnostics failed'
    }
  } catch (e) {
    diagnosticsError.value = e.message || 'Failed to run diagnostics'
  } finally {
    diagnosticsRunning.value = false
  }
}

function getStatusColor(status) {
  switch (status) {
    case 'ok': return 'text-green-500'
    case 'warning': return 'text-amber-500'
    case 'error': return 'text-red-500'
    case 'skipped': return 'text-surface-400'
    default: return 'text-surface-400'
  }
}

function getStatusIcon(status) {
  switch (status) {
    case 'ok': return 'check_circle'
    case 'warning': return 'warning'
    case 'error': return 'cancel'
    case 'skipped': return 'remove_circle_outline'
    default: return 'help'
  }
}

function getStatusBg(status) {
  switch (status) {
    case 'ok': return 'bg-green-50 dark:bg-green-500/10 border-green-200 dark:border-green-500/20'
    case 'warning': return 'bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/20'
    case 'error': return 'bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-500/20'
    case 'skipped': return 'bg-surface-50 dark:bg-surface-800 border-surface-200 dark:border-surface-700'
    default: return 'bg-surface-50 dark:bg-surface-800'
  }
}

function getOverallBg(status) {
  switch (status) {
    case 'ok': return 'bg-green-100 dark:bg-green-500/20'
    case 'warning': return 'bg-amber-100 dark:bg-amber-500/20'
    case 'error': return 'bg-red-100 dark:bg-red-500/20'
    default: return 'bg-surface-100 dark:bg-surface-800'
  }
}

function getOverallColor(status) {
  switch (status) {
    case 'ok': return 'text-green-700 dark:text-green-300'
    case 'warning': return 'text-amber-700 dark:text-amber-300'
    case 'error': return 'text-red-700 dark:text-red-300'
    default: return 'text-surface-500'
  }
}

function getOverallLabel(status) {
  switch (status) {
    case 'ok': return 'All Checks Passed'
    case 'warning': return 'Warnings Detected'
    case 'error': return 'Issues Found'
    default: return 'Unknown'
  }
}

const sections = [
  {
    id: 'nas-unreachable',
    icon: 'cloud_off',
    title: 'NAS Cannot Be Reached',
    color: 'red',
    summary: 'VPS cannot connect to your NAS storage over the network.',
    causes: [
      {
        title: 'VPN tunnel is not connected',
        description: 'If your NAS is on a private network (home/office), the VPN tunnel must be active before the VPS can reach it.',
        fix: 'Go to the VPN Connections tab and check the tunnel status. Start or restart it if needed.',
      },
      {
        title: 'NAS is powered off or unreachable on local network',
        description: 'The NAS device itself may be offline, in standby/hibernation mode, or disconnected from the local network.',
        fix: 'Log into your NAS directly (via DSM, QNAP web UI, or local access) and verify it is online and accessible.',
      },
      {
        title: 'NFS/CIFS service not running on NAS',
        description: 'The file sharing service (NFS or SMB/CIFS) may be disabled or crashed on the NAS.',
        fix: 'On Synology: Control Panel > File Services > enable NFS. Ensure the shared folder has NFS permissions for the VPN subnet (e.g. 10.8.0.0/24).',
      },
      {
        title: 'Wrong NAS IP or path in storage config',
        description: 'The IP address or export path configured in the storage connection may be incorrect or outdated.',
        fix: 'Edit the storage connection and verify the NFS Server IP matches the NAS local IP (e.g. 192.168.1.106) and the NFS Path matches the export (e.g. /volume1/data).',
      },
      {
        title: 'NFS mount timeout',
        description: 'Mount operation hangs and eventually times out. Usually indicates network path is blocked or NAS is not responding on NFS ports.',
        fix: 'Check that NFS ports (TCP/UDP 2049, 111) are open on the NAS firewall. Verify the VPN tunnel routes traffic to the NAS subnet correctly.',
      },
    ],
  },
  {
    id: 'vpn-tunnel',
    icon: 'vpn_lock',
    title: 'VPN & VPN Tunnel Issues',
    color: 'amber',
    summary: 'OpenVPN client on VPS fails to establish tunnel to the NAS network.',
    causes: [
      {
        title: 'Connection timed out to VPN server',
        description: 'The VPS sends packets to the VPN server address but never gets a response. This usually means the packets are not arriving at the VPN server.',
        fix: 'Check that the remote IP/hostname in the OpenVPN config is correct and reachable.',
      },
      {
        title: 'Public IP changed (dynamic IP from ISP)',
        description: 'Your home/office ISP assigned a new public IP address, but the VPN config or DDNS still points to the old one.',
        fix: 'Verify the current public IP from the NAS network (curl ifconfig.me). Update DDNS or VPN config.',
      },
      {
        title: 'IPv6 attempts failing',
        description: 'OpenVPN alternates between IPv4 and IPv6 addresses. If the VPS has no IPv6 route, every other attempt fails instantly.',
        fix: 'Remove IPv6 remote lines from the config or add "proto tcp4" / "proto udp4" to force IPv4 only.',
      },
      {
        title: 'TLS handshake / AUTH_FAILED',
        description: 'Certificates may have expired, or credentials are incorrect.',
        fix: 'Re-export the .ovpn config from the NAS VPN server and recreate the VPN connection with fresh certificates.',
      },
      {
        title: 'Deprecated cipher warnings',
        description: 'Mismatched ciphers between client and server can prevent connection.',
        fix: 'Add "data-ciphers AES-256-CBC:AES-256-GCM:AES-128-GCM" to the client config.',
      },
    ],
  },
  {
    id: 'port-forwarding',
    icon: 'swap_horiz',
    title: 'Port Forwarding (Router)',
    color: 'blue',
    summary: 'Router must forward VPN port from WAN to the NAS for external VPN connections.',
    causes: [
      {
        title: 'No port forward rule exists',
        description: 'The router is not forwarding the VPN port (typically 1194) to the NAS local IP.',
        fix: 'Add a port forward rule: Protocol TCP/UDP, WAN Port 1194, Destination: NAS local IP, LAN Port 1194.',
      },
      {
        title: 'Port forward points to wrong local IP',
        description: 'The NAS was assigned a new local IP via DHCP.',
        fix: 'Set a static IP or DHCP reservation for your NAS on the router.',
      },
      {
        title: 'Source IP filter restricts access',
        description: 'Some routers restrict which external IPs can connect. If your VPS IP changed, traffic will be dropped.',
        fix: 'Update the Source IP filter in the port forward rule, or remove it to allow any source.',
      },
      {
        title: 'ISP uses CGNAT',
        description: 'Your ISP may place your connection behind CGNAT. Port forwarding will not work.',
        fix: 'Contact your ISP and request a real public IP.',
      },
    ],
  },
  {
    id: 'nas-vpn-server',
    icon: 'settings_ethernet',
    title: 'NAS VPN Server Settings',
    color: 'purple',
    summary: 'OpenVPN server on the NAS must be properly configured and running.',
    causes: [
      {
        title: 'OpenVPN server not enabled on NAS',
        description: 'The VPN Server package may not be installed or OpenVPN is disabled.',
        fix: 'Install VPN Server package, enable OpenVPN.',
      },
      {
        title: 'Protocol mismatch (TCP vs UDP)',
        description: 'Client uses TCP but server is set to UDP, or vice versa.',
        fix: 'Ensure protocol matches on both sides.',
      },
      {
        title: '"Allow clients to access server\'s LAN" is disabled',
        description: 'VPN connects but client cannot reach NAS for NFS.',
        fix: 'On Synology VPN Server > OpenVPN: check "Allow clients to access server\'s LAN".',
      },
      {
        title: 'VPN Server certificates expired',
        description: 'Internal certificates may have expired.',
        fix: 'Renew certificate in Control Panel > Security > Certificate, then re-export .ovpn.',
      },
    ],
  },
  {
    id: 'ddns-stale',
    icon: 'dns',
    title: 'DDNS Not Resolving / Stale IP',
    color: 'orange',
    summary: 'Dynamic DNS hostname points to an old IP because the DDNS updater failed.',
    causes: [
      {
        title: 'DDNS update service failed',
        description: 'Synology DDNS updater stopped working. Status shows "Failed" in External Access > DDNS.',
        fix: 'Delete and re-add the DDNS entry in Control Panel > External Access > DDNS.',
      },
      {
        title: 'Synology Account token expired',
        description: 'Authentication token expired, updates silently stop.',
        fix: 'Re-add the DDNS entry to trigger a fresh login.',
      },
      {
        title: 'NAS cannot reach Synology DDNS servers',
        description: 'NAS has no outbound internet or DNS is misconfigured.',
        fix: 'Verify DNS settings on NAS (Control Panel > Network > General). Test: ping google.com from NAS.',
      },
      {
        title: 'ISP changed public IP',
        description: 'Dynamic IP changed between DDNS update intervals.',
        fix: 'Force update in DDNS settings. For stability, request a static IP from ISP.',
      },
    ],
  },
]

const colorMap = {
  red: {
    bg: 'bg-red-50 dark:bg-red-500/10',
    border: 'border-red-200 dark:border-red-500/20',
    icon: 'text-red-500 dark:text-red-400',
    badge: 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-300',
    headerHover: 'hover:bg-red-50 dark:hover:bg-red-500/5',
  },
  amber: {
    bg: 'bg-amber-50 dark:bg-amber-500/10',
    border: 'border-amber-200 dark:border-amber-500/20',
    icon: 'text-amber-500 dark:text-amber-400',
    badge: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300',
    headerHover: 'hover:bg-amber-50 dark:hover:bg-amber-500/5',
  },
  blue: {
    bg: 'bg-blue-50 dark:bg-blue-500/10',
    border: 'border-blue-200 dark:border-blue-500/20',
    icon: 'text-blue-500 dark:text-blue-400',
    badge: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300',
    headerHover: 'hover:bg-blue-50 dark:hover:bg-blue-500/5',
  },
  purple: {
    bg: 'bg-purple-50 dark:bg-purple-500/10',
    border: 'border-purple-200 dark:border-purple-500/20',
    icon: 'text-purple-500 dark:text-purple-400',
    badge: 'bg-purple-100 dark:bg-purple-500/20 text-purple-700 dark:text-purple-300',
    headerHover: 'hover:bg-purple-50 dark:hover:bg-purple-500/5',
  },
  orange: {
    bg: 'bg-orange-50 dark:bg-orange-500/10',
    border: 'border-orange-200 dark:border-orange-500/20',
    icon: 'text-orange-500 dark:text-orange-400',
    badge: 'bg-orange-100 dark:bg-orange-500/20 text-orange-700 dark:text-orange-300',
    headerHover: 'hover:bg-orange-50 dark:hover:bg-orange-500/5',
  },
}

function getColors(color) {
  return colorMap[color] || colorMap.blue
}
</script>

<template>
  <div class="space-y-6 mt-6">

    <!-- ==================== LIVE DIAGNOSTICS ==================== -->
    <div class="card overflow-hidden">
      <button 
        @click="diagnosticsExpanded = !diagnosticsExpanded"
        class="w-full p-4 flex items-center justify-between hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
      >
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-xl text-primary-600 dark:text-primary-400">monitor_heart</span>
          </div>
          <div class="text-left">
            <h3 class="text-lg font-semibold">Live Diagnostics</h3>
            <p class="text-sm text-surface-500">Run real-time connectivity checks across the full VPN/NAS chain</p>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <!-- Quick status pills if results exist -->
          <div v-if="diagnosticsResults" class="hidden sm:flex items-center gap-2">
            <span v-if="statusCounts.error" class="px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-300 text-xs font-medium">
              {{ statusCounts.error }} failed
            </span>
            <span v-if="statusCounts.warning" class="px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300 text-xs font-medium">
              {{ statusCounts.warning }} warnings
            </span>
            <span v-if="statusCounts.ok" class="px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300 text-xs font-medium">
              {{ statusCounts.ok }} passed
            </span>
          </div>
          <span 
            class="material-symbols-rounded text-surface-400 transition-transform"
            :class="{ 'rotate-180': diagnosticsExpanded }"
          >expand_more</span>
        </div>
      </button>

      <div v-if="diagnosticsExpanded" class="px-4 pb-4 border-t border-surface-200 dark:border-surface-700">
        <!-- Config Row -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
          <div>
            <label class="block text-xs font-medium text-surface-500 mb-1">NAS Connection</label>
            <select v-model="selectedNas" class="input text-sm">
              <option value="">-- Manual / All VPN checks --</option>
              <option v-for="c in connections" :key="c.id" :value="c.id">
                {{ c.name }} ({{ c.driver }})
              </option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-surface-500 mb-1">
              VPN Connection
              <span v-if="autoDetectedVpn" class="text-primary-500">(auto-detected)</span>
            </label>
            <select v-model="selectedVpn" class="input text-sm">
              <option value="">{{ autoDetectedVpn ? `Auto: ${autoDetectedVpn}` : '-- Select VPN --' }}</option>
              <option v-for="v in vpnConnections" :key="v.name" :value="v.name">
                {{ v.name }} ({{ v.status }})
              </option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-surface-500 mb-1">NAS IP (override)</label>
            <input v-model="customNasIp" type="text" class="input text-sm" placeholder="e.g. 192.168.1.106" />
          </div>
          <div>
            <label class="block text-xs font-medium text-surface-500 mb-1">Mount Point (override)</label>
            <input v-model="customMountPoint" type="text" class="input text-sm" placeholder="e.g. /mnt/nas" />
          </div>
        </div>

        <!-- Run Button -->
        <div class="mt-4 flex items-center gap-3">
          <button 
            @click="runDiagnostics"
            :disabled="diagnosticsRunning"
            class="btn-primary"
          >
            <span v-if="diagnosticsRunning" class="material-symbols-rounded animate-spin text-lg">progress_activity</span>
            <span v-else class="material-symbols-rounded text-lg">play_arrow</span>
            {{ diagnosticsRunning ? 'Running checks...' : 'Run Diagnostics' }}
          </button>
          <p v-if="diagnosticsRunning" class="text-sm text-surface-500">
            This may take up to 30 seconds while testing connectivity...
          </p>
        </div>

        <!-- Error -->
        <div v-if="diagnosticsError" class="mt-4 p-3 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 rounded-lg">
          <div class="flex items-center gap-2">
            <span class="material-symbols-rounded text-red-500">error</span>
            <p class="text-sm text-red-700 dark:text-red-300">{{ diagnosticsError }}</p>
          </div>
        </div>

        <!-- Results -->
        <div v-if="diagnosticsResults" class="mt-4 space-y-3">
          <!-- Overall Status Banner -->
          <div 
            class="p-4 rounded-xl flex items-center justify-between"
            :class="getOverallBg(diagnosticsResults.overall_status)"
          >
            <div class="flex items-center gap-3">
              <span 
                class="material-symbols-rounded text-2xl"
                :class="getStatusColor(diagnosticsResults.overall_status)"
              >{{ getStatusIcon(diagnosticsResults.overall_status) }}</span>
              <div>
                <p class="font-semibold" :class="getOverallColor(diagnosticsResults.overall_status)">
                  {{ getOverallLabel(diagnosticsResults.overall_status) }}
                </p>
                <p class="text-xs text-surface-500">
                  {{ statusCounts.ok }} passed, {{ statusCounts.warning }} warnings, {{ statusCounts.error }} failed, {{ statusCounts.skipped }} skipped
                  &mdash; {{ diagnosticsResults.timestamp }}
                </p>
                <div v-if="diagnosticsResults.vpn_connected !== undefined" class="flex items-center gap-1.5 mt-1">
                  <span 
                    class="inline-block w-2 h-2 rounded-full"
                    :class="diagnosticsResults.vpn_connected ? 'bg-green-500' : 'bg-red-500'"
                  ></span>
                  <span class="text-xs" :class="diagnosticsResults.vpn_connected ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                    VPN {{ diagnosticsResults.vpn_connected ? 'Connected' : 'Not Connected' }}
                  </span>
                </div>
              </div>
            </div>
            <button @click="runDiagnostics" :disabled="diagnosticsRunning" class="btn-ghost btn-sm">
              <span class="material-symbols-rounded" :class="{ 'animate-spin': diagnosticsRunning }">
                {{ diagnosticsRunning ? 'progress_activity' : 'refresh' }}
              </span>
            </button>
          </div>

          <!-- Individual Checks -->
          <div class="space-y-2">
            <div
              v-for="check in sortedChecks"
              :key="check.key"
              class="p-3 rounded-lg border transition-colors"
              :class="getStatusBg(check.status)"
            >
              <div class="flex items-start gap-3">
                <span 
                  class="material-symbols-rounded text-lg mt-0.5 flex-shrink-0"
                  :class="getStatusColor(check.status)"
                >{{ getStatusIcon(check.status) }}</span>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 flex-wrap">
                    <span class="material-symbols-rounded text-surface-400 text-sm">{{ check.icon }}</span>
                    <span class="font-medium text-sm">{{ check.label }}</span>
                  </div>
                  <p class="text-sm text-surface-600 dark:text-surface-400 mt-0.5">{{ check.message }}</p>
                  
                  <!-- Fix suggestion -->
                  <div v-if="check.fix" class="mt-2 p-2 bg-white/60 dark:bg-surface-900/40 rounded-md">
                    <div class="flex items-start gap-2">
                      <span class="material-symbols-rounded text-primary-500 text-sm mt-0.5 flex-shrink-0">build</span>
                      <p class="text-xs text-surface-600 dark:text-surface-400 whitespace-pre-line">{{ check.fix }}</p>
                    </div>
                  </div>

                  <!-- Expandable details -->
                  <details v-if="check.details" class="mt-2">
                    <summary class="text-xs text-surface-400 cursor-pointer hover:text-surface-600">Show details</summary>
                    <div class="mt-1 p-2 bg-surface-100 dark:bg-surface-800 rounded text-xs font-mono overflow-x-auto">
                      <template v-if="check.details.routes">
                        <div v-for="(route, idx) in check.details.routes" :key="idx" class="text-surface-600 dark:text-surface-400">{{ route }}</div>
                      </template>
                      <template v-else-if="check.details.resolutions">
                        <div v-for="(info, host) in check.details.resolutions" :key="host" class="text-surface-600 dark:text-surface-400">
                          {{ host }} -> {{ Array.isArray(info.resolved) ? info.resolved.join(', ') : (info.resolved || 'FAILED') }}
                        </div>
                      </template>
                      <template v-else-if="check.details.issues">
                        <div v-for="(issue, idx) in check.details.issues" :key="idx" class="text-red-600 dark:text-red-400">{{ issue }}</div>
                      </template>
                      <template v-else-if="check.details.log_snippet">
                        <pre class="text-surface-600 dark:text-surface-400 whitespace-pre-wrap text-[11px]">{{ check.details.log_snippet }}</pre>
                      </template>
                      <template v-else>
                        <pre class="text-surface-600 dark:text-surface-400 whitespace-pre-wrap">{{ JSON.stringify(check.details, null, 2) }}</pre>
                      </template>
                    </div>
                  </details>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ==================== TROUBLESHOOTING REFERENCE GUIDE ==================== -->
    <div class="card p-6">
      <div class="flex items-center gap-3 mb-2">
        <div class="w-10 h-10 rounded-xl bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
          <span class="material-symbols-rounded text-xl text-amber-600 dark:text-amber-400">troubleshoot</span>
        </div>
        <div>
          <h3 class="text-lg font-semibold">Troubleshooting Reference</h3>
          <p class="text-sm text-surface-500">Common connectivity issues between VPS, VPN, and NAS</p>
        </div>
      </div>

      <p class="text-sm text-surface-500 mb-5 pl-[52px]">
        If your NAS storage is unreachable, expand the relevant section below to diagnose the issue.
        Work through them in order -- each layer depends on the previous one being functional.
      </p>

      <!-- Flow Diagram -->
      <div class="flex items-center justify-center gap-0 mb-6 overflow-x-auto py-2">
        <div class="flex items-center gap-2 px-3 py-2 bg-primary-100 dark:bg-primary-500/20 rounded-lg text-sm font-medium text-primary-700 dark:text-primary-300 whitespace-nowrap">
          <span class="material-symbols-rounded text-lg">dns</span>
          DDNS
        </div>
        <span class="material-symbols-rounded text-surface-400 text-lg flex-shrink-0">arrow_forward</span>
        <div class="flex items-center gap-2 px-3 py-2 bg-blue-100 dark:bg-blue-500/20 rounded-lg text-sm font-medium text-blue-700 dark:text-blue-300 whitespace-nowrap">
          <span class="material-symbols-rounded text-lg">swap_horiz</span>
          Router Port
        </div>
        <span class="material-symbols-rounded text-surface-400 text-lg flex-shrink-0">arrow_forward</span>
        <div class="flex items-center gap-2 px-3 py-2 bg-purple-100 dark:bg-purple-500/20 rounded-lg text-sm font-medium text-purple-700 dark:text-purple-300 whitespace-nowrap">
          <span class="material-symbols-rounded text-lg">settings_ethernet</span>
          NAS VPN Server
        </div>
        <span class="material-symbols-rounded text-surface-400 text-lg flex-shrink-0">arrow_forward</span>
        <div class="flex items-center gap-2 px-3 py-2 bg-amber-100 dark:bg-amber-500/20 rounded-lg text-sm font-medium text-amber-700 dark:text-amber-300 whitespace-nowrap">
          <span class="material-symbols-rounded text-lg">vpn_lock</span>
          VPN Tunnel
        </div>
        <span class="material-symbols-rounded text-surface-400 text-lg flex-shrink-0">arrow_forward</span>
        <div class="flex items-center gap-2 px-3 py-2 bg-green-100 dark:bg-green-500/20 rounded-lg text-sm font-medium text-green-700 dark:text-green-300 whitespace-nowrap">
          <span class="material-symbols-rounded text-lg">hard_drive</span>
          NAS Storage
        </div>
      </div>

      <!-- Accordion Sections -->
      <div class="space-y-3">
        <div
          v-for="section in sections"
          :key="section.id"
          class="border rounded-xl overflow-hidden transition-colors"
          :class="[
            expandedSection === section.id 
              ? getColors(section.color).border 
              : 'border-surface-200 dark:border-surface-700'
          ]"
        >
          <button
            @click="toggleSection(section.id)"
            class="w-full px-4 py-3 flex items-center gap-3 transition-colors"
            :class="getColors(section.color).headerHover"
          >
            <div 
              class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
              :class="getColors(section.color).badge"
            >
              <span class="material-symbols-rounded text-lg">{{ section.icon }}</span>
            </div>
            <div class="text-left flex-1 min-w-0">
              <h4 class="font-medium text-sm">{{ section.title }}</h4>
              <p class="text-xs text-surface-500 truncate">{{ section.summary }}</p>
            </div>
            <span 
              class="material-symbols-rounded text-surface-400 transition-transform flex-shrink-0"
              :class="{ 'rotate-180': expandedSection === section.id }"
            >expand_more</span>
          </button>

          <div v-if="expandedSection === section.id" class="px-4 pb-4">
            <div class="border-t border-surface-200 dark:border-surface-700 pt-4">
              <h5 class="text-xs font-semibold uppercase tracking-wider text-surface-400 mb-3">Possible Causes & Fixes</h5>
              <div class="space-y-3">
                <div
                  v-for="(cause, idx) in section.causes"
                  :key="idx"
                  class="p-3 rounded-lg"
                  :class="getColors(section.color).bg"
                >
                  <div class="flex items-start gap-2">
                    <span 
                      class="material-symbols-rounded text-sm mt-0.5 flex-shrink-0" 
                      :class="getColors(section.color).icon"
                    >error</span>
                    <div class="min-w-0">
                      <p class="font-medium text-sm">{{ cause.title }}</p>
                      <p class="text-xs text-surface-500 mt-1">{{ cause.description }}</p>
                      <div class="mt-2 flex items-start gap-2 p-2 bg-white/60 dark:bg-surface-900/40 rounded-md">
                        <span class="material-symbols-rounded text-green-500 text-sm mt-0.5 flex-shrink-0">build</span>
                        <p class="text-xs text-surface-600 dark:text-surface-400">{{ cause.fix }}</p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-4 p-3 bg-primary-50 dark:bg-primary-500/10 rounded-lg flex items-start gap-2">
        <span class="material-symbols-rounded text-primary-500 text-sm mt-0.5">lightbulb</span>
        <p class="text-xs text-primary-700 dark:text-primary-300">
          <strong>Tip:</strong> Work through these from top to bottom. Each layer in the chain (DDNS > Port Forward > VPN Server > VPN Tunnel > NAS) depends on the previous one.
          If DDNS is stale, nothing else will work regardless of their configuration.
        </p>
      </div>
    </div>
  </div>
</template>
