<script setup>
import { ref, onMounted, computed } from 'vue'
import { RouterLink } from 'vue-router'
import api from '../services/api'
import { useToastStore } from '../stores/toast'

const toast = useToastStore()
const loading = ref(true)
const data = ref(null)

// Self-check state
const selfCheck = ref(null)
const selfCheckLoading = ref(false)
const bootstrapRunning = ref(false)

// Snapshot state
const snapshotRunning = ref(false)
const snapshots = ref([])
const deletingSnapshot = ref(null)

const fetchDashboard = async () => {
  try {
    const response = await api.get('/api/dashboard')
    data.value = response.data
  } catch (error) {
    toast.error('Failed to load dashboard')
  } finally {
    loading.value = false
  }
}

const runSelfCheck = async () => {
  selfCheckLoading.value = true
  try {
    const response = await api.get('/api/system/self-check')
    selfCheck.value = response.data
  } catch (error) {
    toast.error('Self-check failed: ' + (error.message || 'Unknown error'))
  } finally {
    selfCheckLoading.value = false
  }
}

const runBootstrap = async () => {
  bootstrapRunning.value = true
  try {
    const response = await api.post('/api/system/bootstrap')
    toast.success('Bootstrap completed')
    // Re-run self-check to see updated state
    await runSelfCheck()
  } catch (error) {
    toast.error('Bootstrap failed: ' + (error.message || 'Unknown error'))
  } finally {
    bootstrapRunning.value = false
  }
}

const takeSnapshot = async () => {
  snapshotRunning.value = true
  try {
    await api.post('/api/system/snapshots', { mode: 'full_clone' })
    toast.success('Snapshot taken successfully')
    await fetchSnapshots()
  } catch (error) {
    toast.error('Snapshot failed: ' + (error.message || 'Unknown error'))
  } finally {
    snapshotRunning.value = false
  }
}

const fetchSnapshots = async () => {
  try {
    const response = await api.get('/api/system/snapshots')
    snapshots.value = response.data || []
  } catch (error) {
    // Non-critical, just log
    console.warn('Could not fetch snapshots:', error.message)
  }
}

const deleteSnapshot = async (snap) => {
  if (!confirm(`Delete snapshot "${snap.label || snap.id}"? This cannot be undone.`)) {
    return
  }
  deletingSnapshot.value = snap.id
  try {
    await api.delete('/api/system/snapshots/' + snap.id)
    snapshots.value = snapshots.value.filter(s => s.id !== snap.id)
    toast.success('Snapshot deleted')
  } catch (error) {
    toast.error('Failed to delete snapshot: ' + (error.response?.data?.error || error.message || 'Unknown error'))
  } finally {
    deletingSnapshot.value = null
  }
}

const selfCheckStatusIcon = computed(() => {
  if (!selfCheck.value) return { icon: 'help', class: 'text-surface-400' }
  switch (selfCheck.value.status) {
    case 'healthy': return { icon: 'check_circle', class: 'text-green-500' }
    case 'degraded': return { icon: 'warning', class: 'text-amber-500' }
    case 'unhealthy': return { icon: 'error', class: 'text-red-500' }
    default: return { icon: 'help', class: 'text-surface-400' }
  }
})

const hasCriticalIssues = computed(() => {
  return selfCheck.value?.issues?.some(i => i.severity === 'critical') || false
})

const hasBootstrapableIssues = computed(() => {
  if (!selfCheck.value) return false
  return selfCheck.value.issues?.some(i =>
    i.fix && (i.fix.includes('bootstrap') || i.fix.includes('migration'))
  ) || false
})

const getStatusColor = (status) => {
  switch (status) {
    case 'active': return 'text-green-400'
    case 'offline': return 'text-surface-400'
    case 'error': return 'text-red-400'
    case 'provisioning': return 'text-amber-400'
    default: return 'text-surface-400'
  }
}

const getStatusDot = (status) => {
  switch (status) {
    case 'active': return 'active'
    case 'error': return 'error'
    case 'provisioning': 
    case 'pending': return 'pending'
    default: return 'offline'
  }
}

const getCheckIcon = (status) => {
  switch (status) {
    case 'ok': return { icon: 'check_circle', class: 'text-green-500' }
    case 'warn': return { icon: 'warning', class: 'text-amber-500' }
    case 'fail': return { icon: 'cancel', class: 'text-red-500' }
    default: return { icon: 'help', class: 'text-surface-400' }
  }
}

const formatDate = (date) => {
  if (!date) return 'Never'
  return new Date(date).toLocaleString()
}

const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(1024))
  return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i]
}

onMounted(async () => {
  await Promise.all([
    fetchDashboard(),
    runSelfCheck(),
    fetchSnapshots(),
  ])
})
</script>

<template>
  <div class="animate-fade-in">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold">Dashboard</h1>
      <div class="flex items-center gap-2">
        <button @click="takeSnapshot" :disabled="snapshotRunning" class="btn-primary btn-sm">
          <span v-if="snapshotRunning" class="spinner-sm mr-1"></span>
          <span class="material-symbols-rounded text-sm">photo_camera</span>
          {{ snapshotRunning ? 'Taking Snapshot...' : 'Take Snapshot' }}
        </button>
        <button @click="fetchDashboard" class="btn-ghost btn-sm">
          <span class="material-symbols-rounded">refresh</span>
          Refresh
        </button>
      </div>
    </div>

    <!-- Self-Check Card (always visible) -->
    <div class="card mb-6">
      <div class="card-header flex items-center justify-between">
        <div class="flex items-center gap-3">
          <span :class="['material-symbols-rounded text-2xl', selfCheckStatusIcon.class]">
            {{ selfCheckStatusIcon.icon }}
          </span>
          <div>
            <h2 class="font-semibold text-default">System Health</h2>
            <p v-if="selfCheck" class="text-sm text-muted">
              {{ selfCheck.summary.passed }}/{{ selfCheck.summary.total_checks }} checks passed
              <template v-if="selfCheck.summary.warnings"> | {{ selfCheck.summary.warnings }} warning(s)</template>
              <template v-if="selfCheck.summary.critical"> | {{ selfCheck.summary.critical }} critical</template>
            </p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button
            v-if="hasBootstrapableIssues"
            @click="runBootstrap"
            :disabled="bootstrapRunning"
            class="btn-primary btn-sm"
          >
            <span v-if="bootstrapRunning" class="spinner-sm mr-1"></span>
            <span class="material-symbols-rounded text-sm">build</span>
            {{ bootstrapRunning ? 'Running...' : 'Run Bootstrap' }}
          </button>
          <button @click="runSelfCheck" :disabled="selfCheckLoading" class="btn-ghost btn-sm">
            <span v-if="selfCheckLoading" class="spinner-sm"></span>
            <span v-else class="material-symbols-rounded text-sm">refresh</span>
          </button>
        </div>
      </div>

      <!-- Loading state -->
      <div v-if="selfCheckLoading && !selfCheck" class="p-6 text-center">
        <div class="spinner-lg text-primary-500"></div>
        <p class="text-muted mt-2 text-sm">Running self-check...</p>
      </div>

      <!-- Check results -->
      <div v-else-if="selfCheck" class="p-0">
        <!-- Issues banner -->
        <div v-if="selfCheck.issues.length > 0" class="border-b border-[rgb(var(--color-border))]">
          <div
            v-for="(issue, idx) in selfCheck.issues"
            :key="idx"
            :class="[
              'flex items-start gap-3 px-4 py-3',
              issue.severity === 'critical' ? 'bg-red-500/10' : 'bg-amber-500/10'
            ]"
          >
            <span :class="[
              'material-symbols-rounded text-lg mt-0.5',
              issue.severity === 'critical' ? 'text-red-500' : 'text-amber-500'
            ]">
              {{ issue.severity === 'critical' ? 'error' : 'warning' }}
            </span>
            <div class="flex-1 min-w-0">
              <p class="text-sm text-default">{{ issue.message }}</p>
              <p v-if="issue.fix" class="text-xs text-muted mt-1 font-mono">{{ issue.fix }}</p>
            </div>
          </div>
        </div>

        <!-- Individual checks grid -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-0 divide-x divide-y divide-[rgb(var(--color-border))]">
          <div
            v-for="check in selfCheck.checks"
            :key="check.id"
            class="px-4 py-3 flex items-center gap-2"
          >
            <span :class="['material-symbols-rounded text-lg', getCheckIcon(check.status).class]">
              {{ getCheckIcon(check.status).icon }}
            </span>
            <span class="text-sm text-default truncate">{{ check.label }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="spinner-lg text-primary-500"></div>
    </div>

    <template v-else-if="data">
      <!-- Stats cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="stat-card">
          <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-primary-500/20 rounded-xl flex items-center justify-center">
              <span class="material-symbols-rounded text-primary-600 dark:text-primary-400 text-2xl">dns</span>
            </div>
            <div>
              <p class="text-muted text-sm">Total Servers</p>
              <p class="text-2xl font-bold text-default">{{ data.servers.total }}</p>
            </div>
          </div>
        </div>

        <div class="stat-card">
          <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-green-500/20 rounded-xl flex items-center justify-center">
              <span class="material-symbols-rounded text-green-600 dark:text-green-400 text-2xl">check_circle</span>
            </div>
            <div>
              <p class="text-muted text-sm">Active</p>
              <p class="text-2xl font-bold text-green-600 dark:text-green-400">{{ data.servers.active }}</p>
            </div>
          </div>
        </div>

        <div class="stat-card">
          <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-red-500/20 rounded-xl flex items-center justify-center">
              <span class="material-symbols-rounded text-red-600 dark:text-red-400 text-2xl">error</span>
            </div>
            <div>
              <p class="text-muted text-sm">Errors</p>
              <p class="text-2xl font-bold text-red-600 dark:text-red-400">{{ data.errors.unresolved }}</p>
            </div>
          </div>
        </div>

        <div class="stat-card">
          <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-amber-500/20 rounded-xl flex items-center justify-center">
              <span class="material-symbols-rounded text-amber-600 dark:text-amber-400 text-2xl">inventory_2</span>
            </div>
            <div>
              <p class="text-muted text-sm">Blueprints</p>
              <p class="text-2xl font-bold text-default">{{ data.blueprints }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Snapshots + Quick Actions row -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Recent Snapshots -->
        <div class="card">
          <div class="card-header flex items-center justify-between">
            <h2 class="font-semibold text-default">Recent Snapshots</h2>
            <button @click="takeSnapshot" :disabled="snapshotRunning" class="text-primary-600 dark:text-primary-400 text-sm hover:underline">
              {{ snapshotRunning ? 'Taking...' : 'Take new' }}
            </button>
          </div>
          <div class="p-0">
            <div v-if="snapshots.length === 0" class="p-6 text-center text-muted">
              <span class="material-symbols-rounded text-4xl mb-2 text-surface-400">photo_camera</span>
              <p>No snapshots yet</p>
              <p class="text-xs mt-1">Take a snapshot to read your server's current state</p>
            </div>
            <div v-else class="divide-y divide-[rgb(var(--color-border))]">
              <RouterLink
                v-for="snap in snapshots.slice(0, 5)"
                :key="snap.id"
                :to="`/blueprints/create?snapshot=${snap.id}`"
                class="flex items-center gap-3 p-4 hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
              >
                <span class="material-symbols-rounded text-primary-500">description</span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-default truncate">
                    {{ snap.label || snap.id }}
                  </p>
                  <p class="text-xs text-muted">
                    {{ formatDate(snap.timestamp) }}
                    <template v-if="snap.categories_count"> | {{ snap.categories_count }} categories</template>
                    <template v-if="snap.size"> | {{ formatBytes(snap.size) }}</template>
                  </p>
                </div>
                <button
                  @click.stop.prevent="deleteSnapshot(snap)"
                  :disabled="deletingSnapshot === snap.id"
                  class="p-1 rounded-lg text-surface-400 hover:text-red-500 hover:bg-red-500/10 transition-colors shrink-0"
                  title="Delete snapshot"
                >
                  <span v-if="deletingSnapshot === snap.id" class="spinner-sm"></span>
                  <span v-else class="material-symbols-rounded text-base">delete</span>
                </button>
                <span class="material-symbols-rounded text-surface-400 text-sm">chevron_right</span>
              </RouterLink>
            </div>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="card">
          <div class="card-header">
            <h2 class="font-semibold text-default">Quick Actions</h2>
          </div>
          <div class="p-4 space-y-3">
            <RouterLink to="/servers/add" class="flex items-center gap-3 p-3 rounded-xl bg-[rgb(var(--color-surface-hover))] hover:bg-primary-500/10 transition-colors">
              <div class="w-10 h-10 bg-primary-500/20 rounded-lg flex items-center justify-center">
                <span class="material-symbols-rounded text-primary-500">add_circle</span>
              </div>
              <div>
                <p class="text-sm font-medium text-default">Add Server</p>
                <p class="text-xs text-muted">Register a new VPS for deployment</p>
              </div>
            </RouterLink>
            <RouterLink to="/blueprints/create" class="flex items-center gap-3 p-3 rounded-xl bg-[rgb(var(--color-surface-hover))] hover:bg-primary-500/10 transition-colors">
              <div class="w-10 h-10 bg-amber-500/20 rounded-lg flex items-center justify-center">
                <span class="material-symbols-rounded text-amber-500">inventory_2</span>
              </div>
              <div>
                <p class="text-sm font-medium text-default">Create Blueprint</p>
                <p class="text-xs text-muted">Generate templates from a snapshot</p>
              </div>
            </RouterLink>
            <RouterLink to="/blueprints" class="flex items-center gap-3 p-3 rounded-xl bg-[rgb(var(--color-surface-hover))] hover:bg-primary-500/10 transition-colors">
              <div class="w-10 h-10 bg-green-500/20 rounded-lg flex items-center justify-center">
                <span class="material-symbols-rounded text-green-500">rocket_launch</span>
              </div>
              <div>
                <p class="text-sm font-medium text-default">Deploy to Server</p>
                <p class="text-xs text-muted">Push a blueprint to a registered server</p>
              </div>
            </RouterLink>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Servers needing attention -->
        <div class="card">
          <div class="card-header flex items-center justify-between">
            <h2 class="font-semibold text-default">Servers Needing Attention</h2>
            <RouterLink to="/servers" class="text-primary-600 dark:text-primary-400 text-sm hover:underline">View all</RouterLink>
          </div>
          <div class="p-0">
            <div v-if="data.servers_needing_attention.length === 0" class="p-6 text-center text-muted">
              <span class="material-symbols-rounded text-4xl mb-2 text-green-600 dark:text-green-400">check_circle</span>
              <p>All servers are healthy</p>
            </div>
            <div v-else class="divide-y divide-[rgb(var(--color-border))]">
              <RouterLink 
                v-for="server in data.servers_needing_attention"
                :key="server.id"
                :to="`/servers/${server.id}`"
                class="flex items-center gap-4 p-4 hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
              >
                <div :class="['status-dot', getStatusDot(server.status)]"></div>
                <div class="flex-1 min-w-0">
                  <p class="font-medium truncate text-default">{{ server.name }}</p>
                  <p class="text-sm text-muted truncate">{{ server.ip_address }}</p>
                </div>
                <div class="text-right">
                  <span :class="['badge', server.status === 'error' ? 'badge-danger' : 'badge-warning']">
                    {{ server.status }}
                  </span>
                </div>
              </RouterLink>
            </div>
          </div>
        </div>

        <!-- Recent errors -->
        <div class="card">
          <div class="card-header flex items-center justify-between">
            <h2 class="font-semibold text-default">Recent Errors</h2>
            <RouterLink to="/errors" class="text-primary-600 dark:text-primary-400 text-sm hover:underline">View all</RouterLink>
          </div>
          <div class="p-0">
            <div v-if="data.recent_errors.length === 0" class="p-6 text-center text-muted">
              <span class="material-symbols-rounded text-4xl mb-2 text-green-600 dark:text-green-400">thumb_up</span>
              <p>No recent errors</p>
            </div>
            <div v-else class="divide-y divide-[rgb(var(--color-border))]">
              <div 
                v-for="error in data.recent_errors"
                :key="error.id"
                class="p-4"
              >
                <div class="flex items-start gap-3">
                  <span :class="[
                    'material-symbols-rounded',
                    error.severity === 'critical' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400'
                  ]">
                    {{ error.severity === 'critical' ? 'error' : 'warning' }}
                  </span>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm truncate text-default">{{ error.message }}</p>
                    <p class="text-xs text-muted mt-1">
                      {{ error.server_name }} - {{ error.source }}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent deployments -->
        <div class="card lg:col-span-2">
          <div class="card-header">
            <h2 class="font-semibold text-default">Recent Deployments</h2>
          </div>
          <div class="p-0">
            <div v-if="data.recent_deployments.length === 0" class="p-6 text-center text-muted">
              <span class="material-symbols-rounded text-4xl mb-2 text-primary-600 dark:text-primary-400">rocket_launch</span>
              <p>No deployments yet</p>
            </div>
            <table v-else class="table">
              <thead>
                <tr>
                  <th>Server</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Started</th>
                  <th>Completed</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="deployment in data.recent_deployments" :key="deployment.id">
                  <td class="font-medium text-default">{{ deployment.server_name }}</td>
                  <td>
                    <div class="flex items-center gap-1.5">
                      <span class="badge badge-info">{{ deployment.type }}</span>
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
                  </td>
                  <td>
                    <span :class="[
                      'badge',
                      deployment.status === 'success' ? 'badge-success' : 
                      deployment.status === 'failed' ? 'badge-danger' :
                      deployment.status === 'running' ? 'badge-warning' : 'badge-neutral'
                    ]">
                      {{ deployment.status }}
                    </span>
                  </td>
                  <td class="text-muted">{{ formatDate(deployment.started_at) }}</td>
                  <td class="text-muted">{{ formatDate(deployment.completed_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
