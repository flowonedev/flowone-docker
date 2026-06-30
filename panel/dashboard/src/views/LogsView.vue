<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'

const toast = useToastStore()

// State
const loading = ref(true)
const logs = ref([])
const stats = ref(null)
const pagination = ref({ total: 0, page: 1, per_page: 50, total_pages: 1 })
const expandedRow = ref(null)
const statsLoading = ref(true)

// Filters
const filters = ref({
  source_app: '',
  severity: '',
  action: '',
  outcome: '',
  search: '',
  from: '',
  to: ''
})

// Stats time range
const statsHours = ref(24)

// Source app config
const sourceApps = [
  { key: '', label: 'All Apps', icon: 'apps' },
  { key: 'panel', label: 'Panel', icon: 'terminal' },
  { key: 'email', label: 'Email', icon: 'mail' },
  { key: 'mailsync', label: 'Mailsync', icon: 'sync' },
  { key: 'collab', label: 'Collab', icon: 'group' },
]

// Severity config
const severities = [
  { key: 'critical', label: 'Critical', color: 'badge-danger', icon: 'error' },
  { key: 'high', label: 'High', color: 'badge-danger', icon: 'warning' },
  { key: 'medium', label: 'Medium', color: 'badge-warning', icon: 'info' },
  { key: 'low', label: 'Low', color: 'badge-info', icon: 'check_circle' },
  { key: 'info', label: 'Info', color: 'badge-neutral', icon: 'description' },
]

const getSeverityConfig = (severity) => {
  return severities.find(s => s.key === severity) || severities[4]
}

const getSourceIcon = (source) => {
  const app = sourceApps.find(a => a.key === source)
  return app ? app.icon : 'help'
}

const getSourceColor = (source) => {
  const colors = {
    panel: 'text-blue-500 bg-blue-500/10',
    email: 'text-purple-500 bg-purple-500/10',
    mailsync: 'text-cyan-500 bg-cyan-500/10',
    collab: 'text-amber-500 bg-amber-500/10',
  }
  return colors[source] || 'text-surface-400 bg-surface-100'
}

// Fetch logs
const fetchLogs = async (page = 1) => {
  loading.value = true
  try {
    const params = {
      page,
      per_page: 50,
      ...Object.fromEntries(Object.entries(filters.value).filter(([_, v]) => v))
    }
    
    const response = await api.get('/logs', { params })
    if (response.data.success) {
      logs.value = response.data.data.data || []
      pagination.value = response.data.data.pagination
    }
  } catch (e) {
    toast.error('Failed to load logs')
  } finally {
    loading.value = false
  }
}

// Fetch stats
const fetchStats = async () => {
  statsLoading.value = true
  try {
    const response = await api.get('/logs/stats', { params: { hours: statsHours.value } })
    if (response.data.success) {
      stats.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to load stats', e)
  } finally {
    statsLoading.value = false
  }
}

// Export CSV
const exportCsv = async () => {
  try {
    const params = Object.fromEntries(
      Object.entries(filters.value).filter(([_, v]) => v)
    )
    
    const response = await api.get('/logs/export', { 
      params,
      responseType: 'blob' 
    })
    
    const url = window.URL.createObjectURL(new Blob([response.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', `audit-logs-${new Date().toISOString().slice(0,10)}.csv`)
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    window.URL.revokeObjectURL(url)
    
    toast.success('Audit logs exported')
  } catch (e) {
    toast.error('Export failed')
  }
}

// Helpers
const formatDate = (dateStr) => {
  if (!dateStr) return '-'
  const d = new Date(dateStr)
  return d.toLocaleString('en-GB', { 
    day: '2-digit', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit', second: '2-digit'
  })
}

const formatRelative = (dateStr) => {
  if (!dateStr) return '-'
  const diff = Date.now() - new Date(dateStr).getTime()
  const mins = Math.floor(diff / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  const days = Math.floor(hrs / 24)
  return `${days}d ago`
}

const toggleRow = (id) => {
  expandedRow.value = expandedRow.value === id ? null : id
}

const applyFilters = () => {
  expandedRow.value = null
  fetchLogs(1)
}

const clearFilters = () => {
  filters.value = { source_app: '', severity: '', action: '', outcome: '', search: '', from: '', to: '' }
  expandedRow.value = null
  fetchLogs(1)
}

const setSourceFilter = (key) => {
  filters.value.source_app = key
  applyFilters()
}

// Computed stats helpers
const statTotal = computed(() => stats.value?.total || 0)

const statBySeverity = computed(() => {
  if (!stats.value?.by_severity) return {}
  const map = {}
  stats.value.by_severity.forEach(s => { map[s.severity] = parseInt(s.count) })
  return map
})

const statBySource = computed(() => {
  if (!stats.value?.by_source) return {}
  const map = {}
  stats.value.by_source.forEach(s => { map[s.source_app] = parseInt(s.count) })
  return map
})

const statFailed = computed(() => {
  if (!stats.value?.by_outcome) return 0
  const failed = stats.value.by_outcome.find(o => o.outcome === 'failed')
  return failed ? parseInt(failed.count) : 0
})

const hasActiveFilters = computed(() => {
  return Object.values(filters.value).some(v => v !== '')
})

// Init
onMounted(() => {
  fetchLogs()
  fetchStats()
})

// Re-fetch stats when time range changes
watch(statsHours, () => fetchStats())
</script>

<template>
  <div>
    <!-- Page Header -->
    <div class="page-header">
      <div>
        <h1 class="page-title flex items-center gap-2">
          <span class="material-symbols-rounded text-primary-500">security</span>
          Audit Log
        </h1>
        <p class="text-surface-500 text-sm mt-1">Centralized security events across all apps</p>
      </div>
      <div class="flex items-center gap-3">
        <select 
          v-model="statsHours" 
          class="input w-auto text-sm"
        >
          <option :value="1">Last 1 hour</option>
          <option :value="6">Last 6 hours</option>
          <option :value="24">Last 24 hours</option>
          <option :value="72">Last 3 days</option>
          <option :value="168">Last 7 days</option>
          <option :value="720">Last 30 days</option>
        </select>
        <button @click="exportCsv" class="btn-secondary btn-sm">
          <span class="material-symbols-rounded text-base">download</span>
          Export CSV
        </button>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
      <!-- Total Events -->
      <div class="stat-card">
        <div class="flex items-center gap-2 mb-2">
          <span class="material-symbols-rounded text-lg text-surface-400">timeline</span>
          <span class="text-xs text-surface-500 uppercase font-medium">Total Events</span>
        </div>
        <div class="stat-value">{{ statsLoading ? '...' : statTotal.toLocaleString() }}</div>
      </div>

      <!-- Critical -->
      <div class="stat-card">
        <div class="flex items-center gap-2 mb-2">
          <span class="material-symbols-rounded text-lg text-red-500">error</span>
          <span class="text-xs text-surface-500 uppercase font-medium">Critical</span>
        </div>
        <div class="stat-value" :class="(statBySeverity.critical || 0) > 0 ? 'text-red-500' : ''">
          {{ statsLoading ? '...' : (statBySeverity.critical || 0) }}
        </div>
      </div>

      <!-- High -->
      <div class="stat-card">
        <div class="flex items-center gap-2 mb-2">
          <span class="material-symbols-rounded text-lg text-orange-500">warning</span>
          <span class="text-xs text-surface-500 uppercase font-medium">High</span>
        </div>
        <div class="stat-value" :class="(statBySeverity.high || 0) > 0 ? 'text-orange-500' : ''">
          {{ statsLoading ? '...' : (statBySeverity.high || 0) }}
        </div>
      </div>

      <!-- Failed -->
      <div class="stat-card">
        <div class="flex items-center gap-2 mb-2">
          <span class="material-symbols-rounded text-lg text-amber-500">block</span>
          <span class="text-xs text-surface-500 uppercase font-medium">Failed</span>
        </div>
        <div class="stat-value" :class="statFailed > 0 ? 'text-amber-500' : ''">
          {{ statsLoading ? '...' : statFailed }}
        </div>
      </div>

      <!-- Sources -->
      <div class="stat-card">
        <div class="flex items-center gap-2 mb-2">
          <span class="material-symbols-rounded text-lg text-blue-500">apps</span>
          <span class="text-xs text-surface-500 uppercase font-medium">Sources</span>
        </div>
        <div class="flex gap-2 mt-1 flex-wrap">
          <template v-if="!statsLoading">
            <span 
              v-for="(count, app) in statBySource" 
              :key="app"
              class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-xs font-medium"
              :class="getSourceColor(app)"
            >
              <span class="material-symbols-rounded text-xs">{{ getSourceIcon(app) }}</span>
              {{ count }}
            </span>
          </template>
          <span v-else class="text-sm text-surface-400">...</span>
        </div>
      </div>
    </div>

    <!-- Source App Tabs -->
    <div class="flex flex-wrap gap-2 mb-4">
      <button
        v-for="app in sourceApps"
        :key="app.key"
        @click="setSourceFilter(app.key)"
        :class="[
          'inline-flex items-center gap-1.5 px-4 py-2 rounded-pill text-sm font-medium transition-all',
          filters.source_app === app.key
            ? 'bg-primary-500 text-white shadow-sm'
            : 'bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-700'
        ]"
      >
        <span class="material-symbols-rounded text-base">{{ app.icon }}</span>
        {{ app.label }}
        <span 
          v-if="app.key && statBySource[app.key]"
          class="ml-1 text-xs opacity-70"
        >
          ({{ statBySource[app.key] }})
        </span>
      </button>
    </div>

    <!-- Filters -->
    <div class="card p-4 mb-6">
      <div class="flex flex-wrap gap-3 items-end">
        <!-- Search -->
        <div class="flex-1 min-w-[200px]">
          <label class="block text-xs font-medium text-surface-500 mb-1.5">Search</label>
          <div class="relative">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-lg text-surface-400">search</span>
            <input
              v-model="filters.search"
              type="text"
              class="input pl-10"
              placeholder="Search action, target, user, IP..."
              @keyup.enter="applyFilters"
            />
          </div>
        </div>
        
        <!-- Severity -->
        <div class="w-36">
          <label class="block text-xs font-medium text-surface-500 mb-1.5">Severity</label>
          <select v-model="filters.severity" class="input">
            <option value="">All</option>
            <option v-for="s in severities" :key="s.key" :value="s.key">{{ s.label }}</option>
          </select>
        </div>
        
        <!-- Outcome -->
        <div class="w-36">
          <label class="block text-xs font-medium text-surface-500 mb-1.5">Outcome</label>
          <select v-model="filters.outcome" class="input">
            <option value="">All</option>
            <option value="success">Success</option>
            <option value="failed">Failed</option>
          </select>
        </div>

        <!-- Actions -->
        <div class="flex gap-2">
          <button @click="applyFilters" class="btn-primary btn-sm">
            <span class="material-symbols-rounded text-base">filter_list</span>
            Filter
          </button>
          <button 
            v-if="hasActiveFilters"
            @click="clearFilters" 
            class="btn-secondary btn-sm"
          >
            <span class="material-symbols-rounded text-base">clear_all</span>
            Clear
          </button>
        </div>
      </div>
    </div>

    <!-- Recent Critical Alerts -->
    <div 
      v-if="stats?.recent_critical?.length" 
      class="mb-6 card border-red-200 dark:border-red-500/30 bg-red-50/50 dark:bg-red-500/5"
    >
      <div class="px-4 py-3 flex items-center gap-2 border-b border-red-200 dark:border-red-500/20">
        <span class="material-symbols-rounded text-red-500">notification_important</span>
        <span class="text-sm font-semibold text-red-700 dark:text-red-400">
          Recent Critical/High Events ({{ stats.recent_critical.length }})
        </span>
      </div>
      <div class="p-3 space-y-2 max-h-48 overflow-y-auto">
        <div 
          v-for="alert in stats.recent_critical" 
          :key="alert.id"
          class="flex items-center gap-3 px-3 py-2 rounded-xl bg-white dark:bg-surface-800/50 text-sm"
        >
          <span 
            class="material-symbols-rounded text-base"
            :class="alert.severity === 'critical' ? 'text-red-500' : 'text-orange-500'"
          >
            {{ alert.severity === 'critical' ? 'error' : 'warning' }}
          </span>
          <span class="font-medium flex-1">{{ alert.action }}</span>
          <span class="text-xs text-surface-500">{{ alert.source_app }}</span>
          <span class="text-xs text-surface-400">{{ formatRelative(alert.created_at) }}</span>
        </div>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner"></span>
    </div>

    <!-- Logs Table -->
    <div v-else class="card overflow-hidden">
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th class="w-8"></th>
              <th>Time</th>
              <th>Source</th>
              <th>Severity</th>
              <th>Action</th>
              <th>Actor / User</th>
              <th>Target</th>
              <th>Outcome</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="log in logs" :key="log.id">
              <!-- Main Row -->
              <tr 
                class="cursor-pointer"
                @click="toggleRow(log.id)"
              >
                <td class="!px-2">
                  <span class="material-symbols-rounded text-base text-surface-400 transition-transform" :class="expandedRow === log.id && 'rotate-90'">
                    chevron_right
                  </span>
                </td>
                <td>
                  <div class="text-sm">{{ formatRelative(log.created_at) }}</div>
                  <div class="text-xs text-surface-400">{{ formatDate(log.created_at) }}</div>
                </td>
                <td>
                  <span 
                    class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-xs font-medium"
                    :class="getSourceColor(log.source_app)"
                  >
                    <span class="material-symbols-rounded text-xs">{{ getSourceIcon(log.source_app) }}</span>
                    {{ log.source_app }}
                  </span>
                </td>
                <td>
                  <span :class="['badge', getSeverityConfig(log.severity).color]">
                    <span class="material-symbols-rounded text-xs">{{ getSeverityConfig(log.severity).icon }}</span>
                    {{ log.severity }}
                  </span>
                </td>
                <td>
                  <span class="font-medium text-sm">{{ log.action }}</span>
                </td>
                <td>
                  <div class="text-sm">{{ log.actor }}</div>
                  <div v-if="log.user_email" class="text-xs text-surface-400 font-mono">{{ log.user_email }}</div>
                </td>
                <td>
                  <span class="text-sm text-surface-500 font-mono truncate-responsive block">{{ log.target || '-' }}</span>
                </td>
                <td>
                  <span :class="[
                    'badge',
                    log.outcome === 'success' ? 'badge-success' : 
                    log.outcome === 'failed' ? 'badge-danger' : 'badge-neutral'
                  ]">
                    {{ log.outcome }}
                  </span>
                </td>
              </tr>

              <!-- Expanded Details Row -->
              <tr v-if="expandedRow === log.id" class="!bg-surface-50 dark:!bg-surface-800/50">
                <td colspan="8" class="!p-4">
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Left: Meta -->
                    <div class="space-y-3">
                      <div class="text-xs font-semibold text-surface-500 uppercase">Event Details</div>
                      <div class="grid grid-cols-2 gap-2 text-sm">
                        <div>
                          <span class="text-surface-400">ID:</span>
                          <span class="ml-1 font-mono">{{ log.id }}</span>
                        </div>
                        <div>
                          <span class="text-surface-400">IP:</span>
                          <span class="ml-1 font-mono">{{ log.ip_address || 'N/A' }}</span>
                        </div>
                        <div v-if="log.user_email">
                          <span class="text-surface-400">Email:</span>
                          <span class="ml-1 font-mono">{{ log.user_email }}</span>
                        </div>
                        <div>
                          <span class="text-surface-400">Time:</span>
                          <span class="ml-1">{{ formatDate(log.created_at) }}</span>
                        </div>
                      </div>
                      
                      <!-- Backup path -->
                      <div v-if="log.backup_path" class="text-sm">
                        <span class="text-surface-400">Backup:</span>
                        <span class="ml-1 font-mono text-xs break-all">{{ log.backup_path }}</span>
                      </div>
                    </div>
                    
                    <!-- Right: Details JSON -->
                    <div>
                      <div class="text-xs font-semibold text-surface-500 uppercase mb-2">Details</div>
                      <pre 
                        v-if="log.details && Object.keys(log.details).length"
                        class="text-xs font-mono bg-surface-100 dark:bg-surface-900 rounded-xl p-3 overflow-auto max-h-48"
                      >{{ JSON.stringify(log.details, null, 2) }}</pre>
                      <span v-else class="text-sm text-surface-400">No additional details</span>
                    </div>
                  </div>
                  
                  <!-- Diff -->
                  <div v-if="log.diff" class="mt-4">
                    <div class="text-xs font-semibold text-surface-500 uppercase mb-2">Config Diff</div>
                    <pre class="text-xs font-mono bg-surface-100 dark:bg-surface-900 rounded-xl p-3 overflow-auto max-h-64 whitespace-pre-wrap">{{ log.diff }}</pre>
                  </div>
                </td>
              </tr>
            </template>
            
            <!-- Empty State -->
            <tr v-if="!logs.length">
              <td colspan="8" class="py-16 text-center text-surface-400">
                <span class="material-symbols-rounded text-5xl mb-3 block">shield</span>
                <p class="text-lg font-medium mb-1">No audit events found</p>
                <p class="text-sm">Events from all connected apps will appear here</p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="pagination.total_pages > 1" class="px-4 py-3 border-t border-surface-100 dark:border-surface-800 flex flex-col sm:flex-row items-center justify-between gap-3">
        <span class="text-sm text-surface-500">
          Page {{ pagination.page }} of {{ pagination.total_pages }} 
          ({{ pagination.total.toLocaleString() }} events)
        </span>
        <div class="flex gap-2">
          <button
            @click="fetchLogs(1)"
            :disabled="pagination.page <= 1"
            class="btn-secondary btn-sm"
          >
            <span class="material-symbols-rounded text-base">first_page</span>
          </button>
          <button
            @click="fetchLogs(pagination.page - 1)"
            :disabled="pagination.page <= 1"
            class="btn-secondary btn-sm"
          >
            <span class="material-symbols-rounded text-base">chevron_left</span>
            Previous
          </button>
          <button
            @click="fetchLogs(pagination.page + 1)"
            :disabled="pagination.page >= pagination.total_pages"
            class="btn-secondary btn-sm"
          >
            Next
            <span class="material-symbols-rounded text-base">chevron_right</span>
          </button>
          <button
            @click="fetchLogs(pagination.total_pages)"
            :disabled="pagination.page >= pagination.total_pages"
            class="btn-secondary btn-sm"
          >
            <span class="material-symbols-rounded text-base">last_page</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
