<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import { RouterLink } from 'vue-router'
import api from '../services/api'
import { useToastStore } from '../stores/toast'
import DeploymentModal from '../components/DeploymentModal.vue'
import { formatLoad } from '../utils/format'

const toast = useToastStore()
const loading = ref(true)
const servers = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const searchQuery = ref('')
const statusFilter = ref('')

// Live deployment progress (for cards that are provisioning)
const progressServer = ref(null)
const showProgressModal = ref(false)
let pollTimer = null

// Batch selection
const selectedServers = ref(new Set())
const batchMode = ref(false)
const batchDeploying = ref(false)
const showBatchModal = ref(false)
const batchType = ref('full_provision') // 'full_provision' | 'app_update'
const batchApps = ref({ panel: true, email: true, agent: true })

const fetchServers = async (silent = false) => {
  if (!silent) loading.value = true
  try {
    const params = new URLSearchParams({
      page: page.value,
      per_page: perPage.value
    })
    if (searchQuery.value) params.append('search', searchQuery.value)
    if (statusFilter.value) params.append('status', statusFilter.value)

    const response = await api.get(`/api/servers?${params}`)
    servers.value = response.data.servers
    total.value = response.data.total
  } catch (error) {
    if (!silent) toast.error('Failed to load servers')
  } finally {
    if (!silent) loading.value = false
  }
}

// Auto-poll while any server is provisioning so cards update live.
const anyProvisioning = computed(() =>
  servers.value.some(s => s.status === 'provisioning' || s.active_deployment)
)

const startPolling = () => {
  if (pollTimer) return
  pollTimer = setInterval(() => fetchServers(true), 4000)
}

const stopPolling = () => {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

watch(anyProvisioning, (active) => {
  if (active) startPolling()
  else stopPolling()
})

const openProgress = (server) => {
  if (!server.active_deployment?.id) return
  progressServer.value = server
  showProgressModal.value = true
}

const closeProgress = () => {
  showProgressModal.value = false
  progressServer.value = null
  fetchServers(true)
}

onUnmounted(stopPolling)

const totalPages = computed(() => Math.ceil(total.value / perPage.value))

const getStatusDot = (status) => {
  switch (status) {
    case 'active': return 'active'
    case 'error': return 'error'
    case 'provisioning': 
    case 'pending': return 'pending'
    default: return 'offline'
  }
}

const getStatusBadge = (status) => {
  switch (status) {
    case 'active': return 'badge-success'
    case 'error': return 'badge-danger'
    case 'provisioning': 
    case 'pending': return 'badge-warning'
    default: return 'badge-neutral'
  }
}

const formatDate = (date) => {
  if (!date) return 'Never'
  const d = new Date(date)
  const now = new Date()
  const diff = (now - d) / 1000

  if (diff < 60) return 'Just now'
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  return d.toLocaleDateString()
}

// Batch selection methods
const toggleServerSelection = (serverId) => {
  if (selectedServers.value.has(serverId)) {
    selectedServers.value.delete(serverId)
  } else {
    selectedServers.value.add(serverId)
  }
  // Force reactivity
  selectedServers.value = new Set(selectedServers.value)
}

const selectAllServers = () => {
  if (selectedServers.value.size === servers.value.length) {
    selectedServers.value = new Set()
  } else {
    selectedServers.value = new Set(servers.value.map(s => s.id))
  }
}

const clearSelection = () => {
  selectedServers.value = new Set()
  batchMode.value = false
}

const openBatchDeploy = () => {
  if (selectedServers.value.size === 0) {
    toast.error('Select at least one server')
    return
  }
  // Default the type based on what's selected: if every selected server is
  // already active, App Update makes sense; otherwise default to Full Provision.
  const selected = servers.value.filter(s => selectedServers.value.has(s.id))
  const allActive = selected.length > 0 && selected.every(s => s.status === 'active')
  batchType.value = allActive ? 'app_update' : 'full_provision'
  batchApps.value = { panel: true, email: true, agent: true }
  showBatchModal.value = true
}

const executeBatchDeploy = async () => {
  const payload = {
    server_ids: Array.from(selectedServers.value),
    type: batchType.value,
  }

  if (batchType.value === 'app_update') {
    const apps = Object.entries(batchApps.value)
      .filter(([_, selected]) => selected)
      .map(([app, _]) => app)
    if (apps.length === 0) {
      toast.error('Select at least one app to update')
      return
    }
    payload.apps = apps
  }

  batchDeploying.value = true

  try {
    const response = await api.post('/api/deployments/batch', payload)

    const { summary } = response.data
    if (batchType.value === 'full_provision') {
      toast.success(`Provisioning started: ${summary.successful}/${summary.total} server(s) running. ${summary.failed > 0 ? summary.failed + ' skipped (no blueprint or busy)' : ''}`)
    } else {
      toast.success(`Batch update completed: ${summary.successful}/${summary.total} successful`)
    }

    showBatchModal.value = false
    clearSelection()
    fetchServers()
  } catch (error) {
    toast.error(error.response?.data?.error || 'Batch deployment failed')
  } finally {
    batchDeploying.value = false
  }
}

const canBatchDeploy = computed(() => {
  if (batchType.value === 'full_provision') return true
  return Object.values(batchApps.value).some(v => v)
})

onMounted(fetchServers)
</script>

<template>
  <div class="animate-fadeIn">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold">Servers</h1>
      <div class="flex items-center gap-3">
        <button 
          @click="batchMode = !batchMode; if (!batchMode) clearSelection()"
          :class="['btn btn-sm', batchMode ? 'btn-secondary' : 'btn-ghost']"
        >
          <span class="material-symbols-rounded">checklist</span>
          {{ batchMode ? 'Exit Selection' : 'Batch Deploy' }}
        </button>
        <RouterLink to="/servers/add" class="btn btn-primary">
          <span class="material-symbols-rounded">add</span>
          Add Server
        </RouterLink>
      </div>
    </div>

    <!-- Batch Action Bar -->
    <div 
      v-if="batchMode && selectedServers.size > 0"
      class="mb-4 p-4 bg-primary-500/10 border border-primary-500/30 rounded-xl flex items-center justify-between"
    >
      <div class="flex items-center gap-3">
        <span class="material-symbols-rounded text-primary-500">check_circle</span>
        <span class="font-medium text-surface-900 dark:text-surface-100">
          {{ selectedServers.size }} server{{ selectedServers.size !== 1 ? 's' : '' }} selected
        </span>
        <button @click="selectAllServers" class="text-sm text-primary-600 dark:text-primary-400 hover:underline">
          {{ selectedServers.size === servers.length ? 'Deselect All' : 'Select All' }}
        </button>
      </div>
      <div class="flex items-center gap-2">
        <button @click="clearSelection" class="btn btn-ghost btn-sm">
          Clear
        </button>
        <button @click="openBatchDeploy" class="btn btn-primary btn-sm">
          <span class="material-symbols-rounded">rocket_launch</span>
          Batch Deploy
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="card mb-6">
      <div class="card-body flex flex-wrap gap-4">
        <div class="flex-1 min-w-[200px]">
          <div class="relative">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
            <input
              v-model="searchQuery"
              @input="page = 1; fetchServers()"
              type="text"
              placeholder="Search servers..."
              class="input w-full pl-10"
            />
          </div>
        </div>
        <select v-model="statusFilter" @change="page = 1; fetchServers()" class="input min-w-[150px]">
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="offline">Offline</option>
          <option value="error">Error</option>
          <option value="provisioning">Provisioning</option>
          <option value="pending">Pending</option>
        </select>
        <button @click="fetchServers" class="btn btn-secondary">
          <span class="material-symbols-rounded">refresh</span>
          Refresh
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="spinner w-10 h-10"></div>
    </div>

    <!-- Empty state -->
    <div v-else-if="servers.length === 0" class="card p-12 text-center">
      <span class="material-symbols-rounded text-6xl text-surface-400 mb-4">dns</span>
      <h2 class="text-xl font-semibold mb-2">No servers found</h2>
      <p class="text-surface-500 dark:text-surface-400 mb-6">Get started by adding your first server</p>
      <RouterLink to="/servers/add" class="btn btn-primary">
        <span class="material-symbols-rounded">add</span>
        Add Server
      </RouterLink>
    </div>

    <!-- Servers grid -->
    <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
      <div
        v-for="server in servers"
        :key="server.id"
        :class="[
          'card transition-colors relative',
          batchMode && selectedServers.has(server.id) ? 'border-primary-500 bg-primary-500/5' : 'hover:border-primary-500/50'
        ]"
      >
        <!-- Selection checkbox (batch mode) -->
        <div 
          v-if="batchMode"
          @click.stop="toggleServerSelection(server.id)"
          :class="[
            'absolute top-3 left-3 w-6 h-6 rounded-lg border-2 flex items-center justify-center cursor-pointer transition-all z-10',
            selectedServers.has(server.id) 
              ? 'bg-primary-500 border-primary-500' 
              : 'border-surface-400 hover:border-primary-400'
          ]"
        >
          <span v-if="selectedServers.has(server.id)" class="material-symbols-rounded text-white text-sm">check</span>
        </div>

        <RouterLink
          :to="`/servers/${server.id}`"
          :class="['card-body block', batchMode ? 'pl-12' : '']"
          @click.native="batchMode && $event.preventDefault()"
        >
          <div class="flex items-start justify-between mb-4">
            <div class="flex items-center gap-3">
              <div :class="['status-dot', getStatusDot(server.status)]"></div>
              <div>
                <h3 class="font-semibold text-surface-900 dark:text-surface-100">{{ server.name }}</h3>
                <p class="text-sm text-surface-500 dark:text-surface-400">{{ server.ip_address }}</p>
              </div>
            </div>
            <span :class="['badge', getStatusBadge(server.status)]">
              {{ server.status }}
            </span>
          </div>

          <div class="space-y-2 text-sm">
            <div class="flex items-center gap-2 text-surface-500 dark:text-surface-400">
              <span class="material-symbols-rounded text-lg">language</span>
              <span class="truncate">{{ server.panel_domain }}</span>
            </div>
            <div class="flex items-center gap-2 text-surface-500 dark:text-surface-400">
              <span class="material-symbols-rounded text-lg">mail</span>
              <span class="truncate">{{ server.email_domain }}</span>
            </div>
            <div v-if="server.os_info" class="flex items-center gap-2 text-surface-500 dark:text-surface-400">
              <span class="material-symbols-rounded text-lg">terminal</span>
              <span class="truncate">{{ server.os_info }}</span>
            </div>
          </div>

          <div class="mt-4 pt-4 border-t border-surface-200 dark:border-surface-700 flex items-center justify-between text-xs text-surface-500 dark:text-surface-400">
            <span>Last heartbeat: {{ formatDate(server.last_heartbeat) }}</span>
            <span v-if="server.error_count > 0" class="text-red-600 dark:text-red-400">
              {{ server.error_count }} errors
            </span>
          </div>

          <!-- Live deployment progress (provisioning) -->
          <div v-if="server.active_deployment" class="mt-3 space-y-1.5">
            <div class="flex items-center justify-between text-xs">
              <span class="text-surface-600 dark:text-surface-400 truncate flex items-center gap-1.5 min-w-0">
                <span class="material-symbols-rounded text-sm animate-spin text-primary-500 shrink-0">sync</span>
                <span class="truncate">{{ server.active_deployment.current_step || 'Starting...' }}</span>
              </span>
              <span class="font-medium text-surface-900 dark:text-surface-100 shrink-0">{{ server.active_deployment.progress || 0 }}%</span>
            </div>
            <div class="h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
              <div
                class="h-full bg-primary-500 rounded-full transition-all duration-500"
                :style="{ width: `${server.active_deployment.progress || 0}%` }"
              ></div>
            </div>
            <button
              @click.stop.prevent="openProgress(server)"
              class="text-xs text-primary-600 dark:text-primary-400 hover:underline flex items-center gap-1"
            >
              <span class="material-symbols-rounded text-sm">visibility</span>
              View live progress
            </button>
          </div>

          <!-- Quick health indicators -->
          <div v-else-if="server.health" class="mt-3 flex gap-2">
            <div class="flex-1 bg-surface-100 dark:bg-surface-700 rounded-lg p-2 text-center">
              <p class="text-xs text-surface-500 dark:text-surface-400">CPU</p>
              <p class="font-medium text-surface-900 dark:text-surface-100">{{ formatLoad(server.health.cpu_load_1m, 1) }}</p>
            </div>
            <div class="flex-1 bg-surface-100 dark:bg-surface-700 rounded-lg p-2 text-center">
              <p class="text-xs text-surface-500 dark:text-surface-400">Memory</p>
              <p class="font-medium text-surface-900 dark:text-surface-100">{{ server.health.memory_percent || '-' }}%</p>
            </div>
            <div class="flex-1 bg-surface-100 dark:bg-surface-700 rounded-lg p-2 text-center">
              <p class="text-xs text-surface-500 dark:text-surface-400">Disk</p>
              <p class="font-medium text-surface-900 dark:text-surface-100">{{ server.health.disk_percent || '-' }}%</p>
            </div>
          </div>
        </RouterLink>
      </div>
    </div>

    <!-- Batch Deploy Modal -->
    <Teleport to="body">
      <div v-if="showBatchModal" class="fixed inset-0 z-50 flex items-center justify-center">
        <div class="absolute inset-0 bg-black/40 dark:bg-black/60 backdrop-blur-sm" @click="showBatchModal = false"></div>
        <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-md mx-4 border border-surface-200 dark:border-surface-700">
          <!-- Header -->
          <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-surface-700">
            <div>
              <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100">Batch Deploy</h2>
              <p class="text-sm text-surface-500 dark:text-surface-400">{{ selectedServers.size }} server{{ selectedServers.size !== 1 ? 's' : '' }} selected</p>
            </div>
            <button @click="showBatchModal = false" class="btn btn-ghost btn-sm">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>

          <!-- Content -->
          <div class="p-6 space-y-4">
            <!-- Deploy type selector -->
            <div class="grid grid-cols-2 gap-2">
              <button
                @click="batchType = 'full_provision'"
                :class="[
                  'p-3 rounded-xl border-2 text-left transition-all',
                  batchType === 'full_provision'
                    ? 'bg-primary-500/20 border-primary-500'
                    : 'bg-surface-100 dark:bg-surface-700/50 border-transparent hover:border-surface-300 dark:hover:border-surface-600'
                ]"
              >
                <span class="material-symbols-rounded" :class="batchType === 'full_provision' ? 'text-primary-500' : 'text-surface-400'">build</span>
                <p class="font-medium text-sm text-surface-900 dark:text-surface-100 mt-1">Full Provision</p>
                <p class="text-xs text-surface-500 dark:text-surface-400">Install everything (fresh servers)</p>
              </button>
              <button
                @click="batchType = 'app_update'"
                :class="[
                  'p-3 rounded-xl border-2 text-left transition-all',
                  batchType === 'app_update'
                    ? 'bg-primary-500/20 border-primary-500'
                    : 'bg-surface-100 dark:bg-surface-700/50 border-transparent hover:border-surface-300 dark:hover:border-surface-600'
                ]"
              >
                <span class="material-symbols-rounded" :class="batchType === 'app_update' ? 'text-primary-500' : 'text-surface-400'">system_update</span>
                <p class="font-medium text-sm text-surface-900 dark:text-surface-100 mt-1">App Update</p>
                <p class="text-xs text-surface-500 dark:text-surface-400">Code only (live servers)</p>
              </button>
            </div>

            <!-- Full provision note -->
            <div v-if="batchType === 'full_provision'" class="p-3 bg-primary-500/10 border border-primary-500/30 rounded-xl text-sm text-surface-600 dark:text-surface-300 flex items-start gap-2">
              <span class="material-symbols-rounded text-lg shrink-0 text-primary-500">info</span>
              <span>Each selected server is provisioned from scratch using its assigned blueprint. Servers with no blueprint, or already provisioning, are skipped.</span>
            </div>

            <p v-if="batchType === 'app_update'" class="text-sm text-surface-600 dark:text-surface-400">
              Select the apps you want to update on all selected servers. Configs will be preserved.
            </p>

            <div v-if="batchType === 'app_update'" class="space-y-3">
              <!-- Panel -->
              <div 
                @click="batchApps.panel = !batchApps.panel"
                :class="[
                  'flex items-center gap-3 p-4 rounded-xl cursor-pointer transition-all',
                  batchApps.panel 
                    ? 'bg-primary-500/20 border-2 border-primary-500' 
                    : 'bg-surface-100 dark:bg-surface-700/50 border-2 border-transparent hover:border-surface-300 dark:hover:border-surface-600'
                ]"
              >
                <span :class="['material-symbols-rounded text-2xl', batchApps.panel ? 'text-primary-500' : 'text-surface-400']">dashboard</span>
                <div class="flex-1">
                  <p class="font-medium text-surface-900 dark:text-surface-100">VPS Admin Panel</p>
                  <p class="text-xs text-surface-500 dark:text-surface-400">PHP backend & API files</p>
                </div>
                <div :class="['w-5 h-5 rounded-full border-2 flex items-center justify-center', batchApps.panel ? 'bg-primary-500 border-primary-500' : 'border-surface-400']">
                  <span v-if="batchApps.panel" class="material-symbols-rounded text-white text-sm">check</span>
                </div>
              </div>
              
              <!-- Email -->
              <div 
                @click="batchApps.email = !batchApps.email"
                :class="[
                  'flex items-center gap-3 p-4 rounded-xl cursor-pointer transition-all',
                  batchApps.email 
                    ? 'bg-primary-500/20 border-2 border-primary-500' 
                    : 'bg-surface-100 dark:bg-surface-700/50 border-2 border-transparent hover:border-surface-300 dark:hover:border-surface-600'
                ]"
              >
                <span :class="['material-symbols-rounded text-2xl', batchApps.email ? 'text-primary-500' : 'text-surface-400']">mail</span>
                <div class="flex-1">
                  <p class="font-medium text-surface-900 dark:text-surface-100">MailFlow Email App</p>
                  <p class="text-xs text-surface-500 dark:text-surface-400">Frontend & backend files</p>
                </div>
                <div :class="['w-5 h-5 rounded-full border-2 flex items-center justify-center', batchApps.email ? 'bg-primary-500 border-primary-500' : 'border-surface-400']">
                  <span v-if="batchApps.email" class="material-symbols-rounded text-white text-sm">check</span>
                </div>
              </div>
              
              <!-- Agent -->
              <div 
                @click="batchApps.agent = !batchApps.agent"
                :class="[
                  'flex items-center gap-3 p-4 rounded-xl cursor-pointer transition-all',
                  batchApps.agent 
                    ? 'bg-primary-500/20 border-2 border-primary-500' 
                    : 'bg-surface-100 dark:bg-surface-700/50 border-2 border-transparent hover:border-surface-300 dark:hover:border-surface-600'
                ]"
              >
                <span :class="['material-symbols-rounded text-2xl', batchApps.agent ? 'text-primary-500' : 'text-surface-400']">smart_toy</span>
                <div class="flex-1">
                  <p class="font-medium text-surface-900 dark:text-surface-100">Fleet Agent</p>
                  <p class="text-xs text-surface-500 dark:text-surface-400">Agent daemon files</p>
                </div>
                <div :class="['w-5 h-5 rounded-full border-2 flex items-center justify-center', batchApps.agent ? 'bg-primary-500 border-primary-500' : 'border-surface-400']">
                  <span v-if="batchApps.agent" class="material-symbols-rounded text-white text-sm">check</span>
                </div>
              </div>
            </div>

            <div v-if="batchType === 'app_update'" class="p-3 bg-amber-500/10 border border-amber-500/30 rounded-xl text-sm text-amber-700 dark:text-amber-300 flex items-start gap-2">
              <span class="material-symbols-rounded text-lg shrink-0">info</span>
              <span>Deployment will run sequentially. Each server's config files (config.local.php, .env) will be preserved.</span>
            </div>
          </div>

          <!-- Footer -->
          <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-surface-200 dark:border-surface-700">
            <button @click="showBatchModal = false" class="btn btn-ghost">
              Cancel
            </button>
            <button 
              @click="executeBatchDeploy" 
              :disabled="!canBatchDeploy || batchDeploying"
              class="btn btn-primary"
            >
              <span v-if="batchDeploying" class="spinner w-4 h-4"></span>
              <span v-else class="material-symbols-rounded">rocket_launch</span>
              Deploy to {{ selectedServers.size }} Server{{ selectedServers.size !== 1 ? 's' : '' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Live deployment progress modal (reuses the full step/log/audit view) -->
    <DeploymentModal
      :show="showProgressModal"
      :server-id="progressServer?.id"
      :server-name="progressServer?.name"
      :resume-deployment-id="progressServer?.active_deployment?.id"
      @close="closeProgress"
      @deployed="closeProgress"
    />

    <!-- Pagination -->
    <div v-if="totalPages > 1" class="mt-6 flex items-center justify-center gap-2">
      <button
        @click="page--; fetchServers()"
        :disabled="page === 1"
        class="btn btn-ghost btn-sm"
      >
        <span class="material-symbols-rounded">chevron_left</span>
      </button>
      <span class="text-surface-500 dark:text-surface-400">Page {{ page }} of {{ totalPages }}</span>
      <button
        @click="page++; fetchServers()"
        :disabled="page === totalPages"
        class="btn btn-ghost btn-sm"
      >
        <span class="material-symbols-rounded">chevron_right</span>
      </button>
    </div>
  </div>
</template>

