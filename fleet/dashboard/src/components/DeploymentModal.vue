<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import api from '../services/api'
import { useToastStore } from '../stores/toast'

const props = defineProps({
  show: Boolean,
  serverId: Number,
  serverName: String,
  currentBlueprintId: Number,
  initialType: String,
  resumeDeploymentId: Number,
})

const emit = defineEmits(['close', 'deployed'])

const toast = useToastStore()

// State
const loading = ref(false)
const deploying = ref(false)
const previewing = ref(false)
const blueprints = ref([])
const deploymentTypes = ref([])
const previewData = ref(null)

// Deployment progress state
const showProgress = ref(false)
const deploymentId = ref(null)
const deploymentStatus = ref(null)
const deploymentProgress = ref(0)
const deploymentStep = ref('')
const deploymentLog = ref('')
const logOffset = ref(0)
let pollInterval = null

// Elapsed / duration tracking. We trust the server-computed elapsed_seconds as a
// base (no client clock skew) and tick locally between polls for a smooth timer.
const startedAt = ref(null)
const completedAt = ref(null)
const serverElapsedSec = ref(null)
const elapsedBaseClientMs = ref(0)
const nowTick = ref(Date.now())
let tickInterval = null

// Step timeline state
const steps = ref([])
const expandedStepLog = ref(null)
const expandedStepData = ref(null)
const loadingStepLog = ref(false)

// Heartbeat tracking
const lastHeartbeat = ref(null)
const heartbeatAge = ref(null)
const deploymentPid = ref(null)
const failedStep = ref(null)
const stepsCompleted = ref(0)
const stepsTotal = ref(0)
const resumedFromStep = ref(null)

// Resume state
const resuming = ref(false)

// Audit state
const auditResults = ref(null)

// Preflight state
const preflightRunning = ref(false)
const preflightData = ref(null)
const preflightExpanded = ref(null)

// Form
const selectedType = ref('config_only')
const selectedBlueprint = ref(null)
const backup = ref(true)
const dryRun = ref(false)

// App update options
const selectedApps = ref({
  panel: true,
  email: true,
  agent: true,
})

// Computed
const selectedTypeInfo = computed(() => {
  return deploymentTypes.value.find(t => t.type === selectedType.value) || {}
})

const requiresBlueprint = computed(() => {
  return selectedTypeInfo.value.requires_blueprint ?? false
})

const canDeploy = computed(() => {
  if (requiresBlueprint.value && !selectedBlueprint.value) {
    return false
  }
  if (selectedType.value === 'app_update') {
    return Object.values(selectedApps.value).some(v => v)
  }
  if (preflightData.value && !preflightData.value.summary?.can_proceed) {
    return false
  }
  return true
})

const preflightAvailable = computed(() => {
  return ['full_provision', 'packages_config'].includes(selectedType.value)
})

const processStale = computed(() => {
  if (!heartbeatAge.value || deploymentStatus.value !== 'running') return false
  return heartbeatAge.value > 60
})

const processDead = computed(() => {
  if (!heartbeatAge.value || deploymentStatus.value !== 'running') return false
  return heartbeatAge.value > 300
})

const canResume = computed(() => {
  return deploymentStatus.value === 'failed' && deploymentId.value
})

const activeStepIndex = computed(() => {
  return steps.value.findIndex(s => s.status === 'running')
})

// Watch for modal open
watch(() => props.show, async (newVal) => {
  if (newVal) {
    if (props.resumeDeploymentId) {
      deploymentId.value = props.resumeDeploymentId
      showProgress.value = true
      deploymentStatus.value = 'running'
      startPolling()
    } else {
      await loadData()
      selectedBlueprint.value = props.currentBlueprintId
      if (props.initialType && ['full_provision', 'config_only', 'packages_config', 'app_update'].includes(props.initialType)) {
        selectedType.value = props.initialType
      }
      selectedApps.value = { panel: true, email: true, agent: true }
    }
  } else {
    previewData.value = null
    preflightData.value = null
    preflightExpanded.value = null
    dryRun.value = false
  }
})

// Methods
const loadData = async () => {
  loading.value = true
  try {
    const [typesRes, blueprintsRes] = await Promise.all([
      api.get('/api/deployments/types'),
      api.get('/api/blueprints'),
    ])
    
    deploymentTypes.value = typesRes.data
    blueprints.value = blueprintsRes.data
  } catch (error) {
    toast.error('Failed to load deployment options')
  } finally {
    loading.value = false
  }
}

const preview = async () => {
  if (!canDeploy.value) return
  
  previewing.value = true
  previewData.value = null
  
  try {
    const response = await api.post('/api/deployments/preview', {
      server_id: props.serverId,
      blueprint_id: selectedBlueprint.value,
      type: selectedType.value,
    })
    
    previewData.value = response.data
    toast.success('Preview generated')
  } catch (error) {
    toast.error(error.response?.data?.error || 'Preview failed')
  } finally {
    previewing.value = false
  }
}

const runPreflight = async () => {
  preflightRunning.value = true
  preflightData.value = null
  preflightExpanded.value = null

  try {
    const response = await api.post('/api/deployments/preflight', {
      server_id: props.serverId,
      blueprint_id: selectedBlueprint.value,
    })

    preflightData.value = response.data

    if (response.data?.summary?.can_proceed) {
      toast.success('Preflight passed -- ready to deploy')
    } else {
      toast.error('Preflight found critical issues -- fix them before deploying')
    }
  } catch (error) {
    toast.error(error.response?.data?.error || 'Preflight check failed')
  } finally {
    preflightRunning.value = false
  }
}

const deploy = async () => {
  if (!canDeploy.value) return
  
  deploying.value = true
  
  try {
    const payload = {
      server_id: props.serverId,
      blueprint_id: selectedBlueprint.value,
      type: selectedType.value,
      backup: backup.value,
      dry_run: dryRun.value,
    }
    
    if (selectedType.value === 'app_update') {
      payload.apps = Object.entries(selectedApps.value)
        .filter(([_, selected]) => selected)
        .map(([app, _]) => app)
    }

    if (preflightData.value) {
      payload.preflight_results = preflightData.value
    }
    
    const response = await api.post('/api/deployments', payload)
    
    if (response.data?.deployment_id) {
      deploymentId.value = response.data.deployment_id
      showProgress.value = true
      deploying.value = false
      startPolling()
    } else {
      toast.success(response.message || 'Deployment completed')
      emit('deployed', response.data)
      emit('close')
    }
  } catch (error) {
    toast.error(error.response?.data?.error || 'Deployment failed')
    deploying.value = false
  }
}

// Polling for deployment progress
const startPolling = () => {
  if (pollInterval) clearInterval(pollInterval)
  fetchProgress()
  pollInterval = setInterval(fetchProgress, 2000)
  // Smooth 1s ticker for the elapsed timer between 2s polls.
  if (!tickInterval) {
    tickInterval = setInterval(() => { nowTick.value = Date.now() }, 1000)
  }
}

const stopPolling = () => {
  if (pollInterval) {
    clearInterval(pollInterval)
    pollInterval = null
  }
  if (tickInterval) {
    clearInterval(tickInterval)
    tickInterval = null
  }
}

// Live elapsed time in ms: server elapsed base + local delta while running.
const elapsedMs = computed(() => {
  if (serverElapsedSec.value == null) return null
  let ms = serverElapsedSec.value * 1000
  if (['running', 'pending'].includes(deploymentStatus.value)) {
    ms += Math.max(0, nowTick.value - elapsedBaseClientMs.value)
  }
  return ms
})

const elapsedLabel = computed(() => {
  if (elapsedMs.value == null) return ''
  return formatDuration(elapsedMs.value)
})

const fetchProgress = async () => {
  if (!deploymentId.value) return
  
  try {
    const [logsRes, stepsRes] = await Promise.all([
      api.get(`/api/deployments/${deploymentId.value}/logs`, {
        params: { offset: logOffset.value }
      }),
      api.get(`/api/deployments/${deploymentId.value}/steps`),
    ])
    
    // Update step data
    if (stepsRes.data) {
      steps.value = stepsRes.data
    }

    // Update heartbeat / progress data
    const data = logsRes.data
    deploymentProgress.value = data.progress || 0
    deploymentStep.value = data.current_step || 'Initializing...'
    lastHeartbeat.value = data.last_heartbeat
    heartbeatAge.value = data.heartbeat_age_seconds
    deploymentPid.value = data.pid
    failedStep.value = data.failed_step
    stepsCompleted.value = data.steps_completed || 0
    stepsTotal.value = data.steps_total || 0
    resumedFromStep.value = data.resumed_from_step
    if (data.audit_results) auditResults.value = data.audit_results

    // Timing: anchor the server-computed elapsed to the local clock for ticking.
    startedAt.value = data.started_at
    completedAt.value = data.completed_at
    if (data.elapsed_seconds != null) {
      serverElapsedSec.value = data.elapsed_seconds
      elapsedBaseClientMs.value = Date.now()
    }

    // Determine final status
    const finalStatuses = ['success', 'failed', 'cancelled']
    if (finalStatuses.includes(data.status)) {
      deploymentStatus.value = data.status
    } else {
      deploymentStatus.value = data.status
    }
    
    if (data.content) {
      deploymentLog.value += data.content
      logOffset.value = data.offset
      
      setTimeout(() => {
        const logEl = document.querySelector('.deployment-log')
        if (logEl) logEl.scrollTop = logEl.scrollHeight
      }, 50)
    }
    
    if (finalStatuses.includes(deploymentStatus.value)) {
      stopPolling()
      // Fetch steps one last time
      const finalSteps = await api.get(`/api/deployments/${deploymentId.value}/steps`)
      if (finalSteps.data) steps.value = finalSteps.data

      if (deploymentStatus.value === 'success') {
        toast.success('Deployment completed successfully')
        if (auditResults.value) activeTab.value = 'audit'
      } else if (deploymentStatus.value === 'failed') {
        toast.error('Deployment failed')
      }
    }
  } catch (error) {
    console.error('Failed to fetch progress:', error)
  }
}

const fetchStepLog = async (stepKey) => {
  if (expandedStepLog.value === stepKey) {
    expandedStepLog.value = null
    expandedStepData.value = null
    return
  }
  
  expandedStepLog.value = stepKey
  loadingStepLog.value = true
  
  try {
    const res = await api.get(`/api/deployments/${deploymentId.value}/steps/${stepKey}/log`)
    expandedStepData.value = res.data
  } catch (error) {
    expandedStepData.value = { command_log: 'Failed to load step log', error_message: error.message }
  } finally {
    loadingStepLog.value = false
  }
}

const resumeDeployment = async (skipFailed = false) => {
  if (!canResume.value) return
  
  resuming.value = true
  try {
    await api.post(`/api/deployments/${deploymentId.value}/resume`, {
      skip_failed: skipFailed,
    })
    
    deploymentStatus.value = 'running'
    failedStep.value = null
    startPolling()
    toast.success(skipFailed ? 'Resuming (skipping failed step)' : 'Retrying failed step')
  } catch (error) {
    toast.error(error.response?.data?.error || 'Failed to resume deployment')
  } finally {
    resuming.value = false
  }
}

const closeProgress = () => {
  stopPolling()
  showProgress.value = false
  deploymentId.value = null
  deploymentStatus.value = null
  deploymentProgress.value = 0
  deploymentStep.value = ''
  deploymentLog.value = ''
  logOffset.value = 0
  steps.value = []
  expandedStepLog.value = null
  expandedStepData.value = null
  lastHeartbeat.value = null
  heartbeatAge.value = null
  failedStep.value = null
  stepsCompleted.value = 0
  stepsTotal.value = 0
  startedAt.value = null
  completedAt.value = null
  serverElapsedSec.value = null
  preflightData.value = null
  preflightExpanded.value = null
  auditResults.value = null
  activeTab.value = 'steps'
  
  emit('deployed', { deployment_id: deploymentId.value })
  emit('close')
}

const cancelDeployment = async () => {
  if (!deploymentId.value) return
  
  try {
    await api.post(`/api/deployments/${deploymentId.value}/cancel`)
    toast.info('Deployment cancelled')
  } catch (error) {
    toast.error('Failed to cancel deployment')
  }
}

const close = () => {
  if (showProgress.value) {
    closeProgress()
  } else {
    emit('close')
  }
}

onUnmounted(() => {
  stopPolling()
})

const getTypeIcon = (type) => {
  const icons = {
    'full_provision': 'build',
    'config_only': 'settings',
    'packages_config': 'deployed_code',
    'app_update': 'system_update',
    'agent_update': 'smart_toy',
    'ssl_renew': 'lock',
  }
  return icons[type] || 'rocket_launch'
}

const getStepIcon = (status) => {
  const map = {
    success: 'check_circle',
    failed: 'error',
    running: 'sync',
    skipped: 'skip_next',
    warning: 'warning',
    pending: 'radio_button_unchecked',
  }
  return map[status] || 'radio_button_unchecked'
}

const getStepColor = (status) => {
  const map = {
    success: 'text-green-500',
    failed: 'text-red-500',
    running: 'text-primary-500 animate-spin',
    skipped: 'text-surface-400 dark:text-surface-500',
    warning: 'text-amber-500',
    pending: 'text-surface-300 dark:text-surface-600',
  }
  return map[status] || 'text-surface-300 dark:text-surface-600'
}

const getErrorTypeBadge = (errorType) => {
  const map = {
    ssh_error: { label: 'SSH Error', class: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400' },
    timeout: { label: 'Timeout', class: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' },
    race_condition: { label: 'Race Condition', class: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' },
    dependency: { label: 'Dependency', class: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' },
    server_issue: { label: 'Server Issue', class: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' },
    script_bug: { label: 'Script Bug', class: 'bg-pink-100 text-pink-700 dark:bg-pink-900/30 dark:text-pink-400' },
    unknown: { label: 'Unknown', class: 'bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-400' },
  }
  return map[errorType] || map.unknown
}

const formatDuration = (ms) => {
  if (!ms) return ''
  if (ms < 1000) return `${ms}ms`
  const sec = Math.round(ms / 1000)
  if (sec < 60) return `${sec}s`
  const min = Math.floor(sec / 60)
  const remainSec = sec % 60
  return `${min}m ${remainSec}s`
}

const getPreflightIcon = (status) => {
  const map = {
    pass: 'check_circle',
    warn: 'warning',
    fail: 'cancel',
  }
  return map[status] || 'help'
}

const getPreflightColor = (status) => {
  const map = {
    pass: 'text-green-500',
    warn: 'text-amber-500',
    fail: 'text-red-500',
  }
  return map[status] || 'text-surface-400'
}

const getPreflightBgColor = (status) => {
  const map = {
    pass: 'bg-green-50 dark:bg-green-900/10',
    warn: 'bg-amber-50 dark:bg-amber-900/10',
    fail: 'bg-red-50 dark:bg-red-900/10',
  }
  return map[status] || ''
}

const getCategoryLabel = (category) => {
  const map = {
    critical: 'Critical',
    important: 'Important',
    info: 'Info',
  }
  return map[category] || category
}

const getCategoryBadge = (category) => {
  const map = {
    critical: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    important: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
    info: 'bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-400',
  }
  return map[category] || map.info
}

const activeTab = ref('steps')

const getAuditStatusIcon = (status) => {
  if (status === 'pass') return 'check_circle'
  if (status === 'fail') return 'cancel'
  return 'warning'
}

const getAuditStatusColor = (status) => {
  if (status === 'pass') return 'text-green-500'
  if (status === 'fail') return 'text-red-500'
  return 'text-amber-500'
}

const auditChecksByCategory = computed(() => {
  if (!auditResults.value?.checks) return {}
  const grouped = {}
  const labels = {
    services: 'Services',
    packages: 'Packages',
    database: 'Databases',
    filesystem: 'File System',
    ssl: 'SSL Certificates',
    http: 'HTTP Connectivity',
    application: 'Applications',
    agent: 'Fleet Agent',
    firewall: 'Firewall',
    security: 'Security',
  }
  for (const check of auditResults.value.checks) {
    const cat = check.category
    if (!grouped[cat]) grouped[cat] = { label: labels[cat] || cat, items: [] }
    grouped[cat].items.push(check)
  }
  return grouped
})

const auditFixingCheck = ref(null)
const auditFixingAll = ref(false)

const auditHasFixableIssues = computed(() => {
  if (!auditResults.value?.checks) return false
  return auditResults.value.checks.some(c => c.fix_action && c.status !== 'pass')
})

const fixAuditCheckFromModal = async (check) => {
  if (!check.fix_action || !props.serverId) return
  auditFixingCheck.value = check.name
  try {
    const response = await api.post(`/api/servers/${props.serverId}/audit/fix`, {
      action: check.fix_action,
      params: check.fix_params || {},
    })
    toast.success(response.message || 'Fix applied')
    check.status = 'pass'
    check.detail = response.data?.message || 'Fixed'
    delete check.fix_action
    delete check.fix_params
  } catch (error) {
    toast.error(error.response?.data?.error || 'Fix failed')
  } finally {
    auditFixingCheck.value = null
  }
}

const fixAllAuditFromModal = async () => {
  if (!props.serverId) return
  auditFixingAll.value = true
  try {
    const response = await api.post(`/api/servers/${props.serverId}/audit/fix`, {
      action: 'fix_all',
      params: {},
    })
    toast.success(response.message || 'Fixes applied')
  } catch (error) {
    toast.error(error.response?.data?.error || 'Fix all failed')
  } finally {
    auditFixingAll.value = false
  }
}

onMounted(() => {
  if (props.show) {
    loadData()
  }
})
</script>

<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center">
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-black/40 dark:bg-black/60 backdrop-blur-sm" @click="close"></div>
      
      <!-- Modal -->
      <div class="relative bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-3xl mx-4 max-h-[90vh] overflow-hidden flex flex-col border border-surface-200 dark:border-surface-700">
        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-surface-700">
          <div>
            <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-100">
              {{ showProgress ? 'Deployment Progress' : 'Deploy to Server' }}
            </h2>
            <p class="text-sm text-surface-500 dark:text-surface-400">{{ serverName }}</p>
          </div>
          <button 
            @click="close" 
            class="btn btn-ghost btn-sm"
            :disabled="deploymentStatus === 'running' || deploymentStatus === 'pending'"
          >
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-6 space-y-6">
          <!-- Deployment Progress View -->
          <template v-if="showProgress">
            <div class="space-y-5">
              <!-- Status Badge + Heartbeat Warning -->
              <div class="space-y-2">
                <div class="flex items-center justify-center gap-3">
                  <span v-if="deploymentStatus === 'running' || deploymentStatus === 'pending'" class="relative flex h-3 w-3">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3 bg-primary-500"></span>
                  </span>
                  <span v-else-if="deploymentStatus === 'success'" class="material-symbols-rounded text-3xl text-green-500">check_circle</span>
                  <span v-else-if="deploymentStatus === 'failed'" class="material-symbols-rounded text-3xl text-red-500">error</span>
                  <span v-else-if="deploymentStatus === 'cancelled'" class="material-symbols-rounded text-3xl text-amber-500">cancel</span>
                  
                  <span class="text-lg font-semibold text-surface-900 dark:text-surface-100">
                    {{ deploymentStatus === 'running' ? 'Deploying...' : 
                       deploymentStatus === 'pending' ? 'Starting...' :
                       deploymentStatus === 'success' ? 'Deployment Complete' :
                       deploymentStatus === 'failed' ? 'Deployment Failed' :
                       deploymentStatus === 'cancelled' ? 'Deployment Cancelled' : 'Processing...' }}
                  </span>
                </div>

                <!-- Elapsed (live) / total duration (final) -->
                <div v-if="elapsedLabel" class="flex items-center justify-center gap-1.5 text-sm text-surface-500 dark:text-surface-400">
                  <span class="material-symbols-rounded text-base">schedule</span>
                  <span v-if="['running', 'pending'].includes(deploymentStatus)">Elapsed: {{ elapsedLabel }}</span>
                  <span v-else>Took {{ elapsedLabel }}</span>
                </div>

                <!-- Heartbeat stale warning -->
                <div v-if="processDead" class="flex items-center gap-2 justify-center p-2 rounded-xl bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 text-sm">
                  <span class="material-symbols-rounded text-lg">heart_broken</span>
                  Process appears dead (no heartbeat for {{ Math.round(heartbeatAge / 60) }}m). You can resume below.
                </div>
                <div v-else-if="processStale" class="flex items-center gap-2 justify-center p-2 rounded-xl bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400 text-sm">
                  <span class="material-symbols-rounded text-lg">warning</span>
                  Process heartbeat stale ({{ heartbeatAge }}s ago) -- may be running a long command
                </div>

                <!-- Resumed badge -->
                <div v-if="resumedFromStep" class="flex items-center gap-2 justify-center text-xs text-primary-600 dark:text-primary-400">
                  <span class="material-symbols-rounded text-sm">replay</span>
                  Resumed from: {{ resumedFromStep }}
                </div>
              </div>

              <!-- Progress Bar -->
              <div class="space-y-2">
                <div class="flex justify-between text-sm">
                  <span class="text-surface-600 dark:text-surface-400">{{ deploymentStep }}</span>
                  <span class="font-medium text-surface-900 dark:text-surface-100">
                    {{ stepsCompleted }}/{{ stepsTotal }} steps &middot; {{ deploymentProgress }}%
                  </span>
                </div>
                <div class="h-3 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                  <div 
                    class="h-full rounded-full transition-all duration-500"
                    :class="[
                      deploymentStatus === 'failed' ? 'bg-red-500' :
                      deploymentStatus === 'success' ? 'bg-green-500' :
                      'bg-primary-500'
                    ]"
                    :style="{ width: `${deploymentProgress}%` }"
                  ></div>
                </div>
              </div>

              <!-- Tab switcher: Steps / Log / Audit -->
              <div class="flex gap-1 bg-surface-100 dark:bg-surface-700/50 rounded-xl p-1">
                <button
                  @click="activeTab = 'steps'"
                  :class="[
                    'flex-1 py-2 px-3 text-sm font-medium rounded-lg transition-all flex items-center justify-center gap-2',
                    activeTab === 'steps' ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm' : 'text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-300'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">timeline</span>
                  Steps
                </button>
                <button
                  @click="activeTab = 'log'"
                  :class="[
                    'flex-1 py-2 px-3 text-sm font-medium rounded-lg transition-all flex items-center justify-center gap-2',
                    activeTab === 'log' ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm' : 'text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-300'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">terminal</span>
                  Full Log
                </button>
                <button
                  @click="activeTab = 'audit'"
                  :class="[
                    'flex-1 py-2 px-3 text-sm font-medium rounded-lg transition-all flex items-center justify-center gap-2',
                    activeTab === 'audit' ? 'bg-white dark:bg-surface-600 text-surface-900 dark:text-surface-100 shadow-sm' : 'text-surface-500 dark:text-surface-400 hover:text-surface-700 dark:hover:text-surface-300'
                  ]"
                >
                  <span class="material-symbols-rounded text-lg">verified</span>
                  Audit
                  <span v-if="auditResults" :class="[
                    'w-2 h-2 rounded-full',
                    auditResults.failed > 0 ? 'bg-red-500' : auditResults.warnings > 0 ? 'bg-amber-500' : 'bg-green-500'
                  ]"></span>
                </button>
              </div>

              <!-- Steps Timeline -->
              <div v-if="activeTab === 'steps' && steps.length" class="space-y-0">
                <div
                  v-for="(step, idx) in steps"
                  :key="step.step_key"
                  class="relative"
                >
                  <!-- Connector line -->
                  <div
                    v-if="idx < steps.length - 1"
                    class="absolute left-[15px] top-[32px] w-0.5 h-[calc(100%-16px)]"
                    :class="step.status === 'success' ? 'bg-green-300 dark:bg-green-700' : 'bg-surface-200 dark:bg-surface-700'"
                  ></div>

                  <div
                    class="flex items-start gap-3 py-2 px-2 rounded-xl cursor-pointer transition-colors hover:bg-surface-50 dark:hover:bg-surface-700/30"
                    :class="{ 'bg-red-50/50 dark:bg-red-900/10': step.status === 'failed' }"
                    @click="step.status === 'failed' || step.status === 'success' || step.status === 'warning' ? fetchStepLog(step.step_key) : null"
                  >
                    <!-- Status icon -->
                    <span
                      class="material-symbols-rounded text-[22px] mt-0.5 shrink-0 relative z-10"
                      :class="getStepColor(step.status)"
                    >{{ getStepIcon(step.status) }}</span>

                    <!-- Step info -->
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">
                          {{ step.step_name }}
                        </span>
                        <span v-if="step.duration_ms" class="text-xs text-surface-400 dark:text-surface-500 shrink-0">
                          {{ formatDuration(step.duration_ms) }}
                        </span>
                      </div>

                      <!-- Error info -->
                      <div v-if="step.status === 'failed' && step.error_message" class="mt-1 space-y-1">
                        <div class="flex items-center gap-2">
                          <span
                            v-if="step.error_type"
                            class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide"
                            :class="getErrorTypeBadge(step.error_type).class"
                          >{{ getErrorTypeBadge(step.error_type).label }}</span>
                          <span v-if="step.retry_count > 0" class="text-[10px] text-surface-400 dark:text-surface-500">
                            retried {{ step.retry_count }}x
                          </span>
                        </div>
                        <p class="text-xs text-red-600 dark:text-red-400 line-clamp-2">{{ step.error_message }}</p>
                      </div>

                      <!-- Skipped info -->
                      <p v-if="step.status === 'skipped'" class="text-xs text-surface-400 dark:text-surface-500 mt-0.5">Skipped</p>
                    </div>

                    <!-- Expand indicator for failed/completed steps -->
                    <span
                      v-if="step.status === 'failed' || step.status === 'success' || step.status === 'warning'"
                      class="material-symbols-rounded text-lg text-surface-400 dark:text-surface-500 shrink-0 mt-0.5 transition-transform"
                      :class="{ 'rotate-180': expandedStepLog === step.step_key }"
                    >expand_more</span>
                  </div>

                  <!-- Expanded step log -->
                  <div
                    v-if="expandedStepLog === step.step_key"
                    class="ml-[34px] mb-2"
                  >
                    <div v-if="loadingStepLog" class="flex items-center gap-2 p-3 text-sm text-surface-500">
                      <div class="spinner w-4 h-4"></div>
                      Loading step log...
                    </div>
                    <div v-else-if="expandedStepData" class="bg-surface-900 dark:bg-black rounded-xl p-3 max-h-40 overflow-y-auto font-mono text-[11px] leading-relaxed text-green-400">
                      <pre class="whitespace-pre-wrap">{{ expandedStepData.command_log || 'No command log recorded for this step' }}</pre>
                    </div>
                  </div>
                </div>
              </div>

              <!-- No steps yet -->
              <div v-if="activeTab === 'steps' && !steps.length" class="text-center py-6 text-sm text-surface-500 dark:text-surface-400">
                <span class="material-symbols-rounded text-3xl mb-2 block">hourglass_empty</span>
                Waiting for step data...
              </div>

              <!-- Full Log Output -->
              <div v-if="activeTab === 'log'" class="space-y-2">
                <div class="flex items-center justify-between">
                  <span class="text-sm font-medium text-surface-700 dark:text-surface-300">Deployment Log</span>
                  <button 
                    @click="deploymentLog = ''; logOffset = 0" 
                    class="text-xs text-surface-500 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100"
                  >
                    Clear
                  </button>
                </div>
                <div class="deployment-log bg-surface-900 dark:bg-black rounded-xl p-4 h-56 overflow-y-auto font-mono text-xs text-green-400 leading-relaxed">
                  <pre v-if="deploymentLog" class="whitespace-pre-wrap">{{ deploymentLog }}</pre>
                  <p v-else class="text-surface-500">Waiting for logs...</p>
                </div>
              </div>

              <!-- Audit Results -->
              <div v-if="activeTab === 'audit'" class="space-y-3">
                <div v-if="!auditResults" class="text-center py-8 text-sm text-surface-500 dark:text-surface-400">
                  <span class="material-symbols-rounded text-4xl mb-2 block">verified</span>
                  <p v-if="deploymentStatus === 'running'">Audit runs automatically after deployment completes</p>
                  <p v-else>No audit data available for this deployment</p>
                </div>
                <template v-else>
                  <!-- Audit Summary -->
                  <div class="flex items-center gap-3 p-3 rounded-xl" :class="[
                    auditResults.failed > 0 
                      ? 'bg-red-500/10 border border-red-500/20' 
                      : auditResults.warnings > 0 
                        ? 'bg-amber-500/10 border border-amber-500/20'
                        : 'bg-green-500/10 border border-green-500/20'
                  ]">
                    <span class="material-symbols-rounded text-2xl" :class="[
                      auditResults.failed > 0 ? 'text-red-500' : auditResults.warnings > 0 ? 'text-amber-500' : 'text-green-500'
                    ]">
                      {{ auditResults.failed > 0 ? 'gpp_bad' : auditResults.warnings > 0 ? 'gpp_maybe' : 'verified_user' }}
                    </span>
                    <div class="flex-1">
                      <p class="font-semibold text-sm" :class="[
                        auditResults.failed > 0 ? 'text-red-600 dark:text-red-400' : auditResults.warnings > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-green-600 dark:text-green-400'
                      ]">
                        {{ auditResults.failed > 0 ? 'Issues Found' : auditResults.warnings > 0 ? 'Completed with Warnings' : 'All Checks Passed' }}
                      </p>
                      <p class="text-xs text-surface-500 dark:text-surface-400">
                        {{ auditResults.total }} checks: {{ auditResults.passed }} passed, {{ auditResults.warnings || 0 }} warnings, {{ auditResults.failed }} failed
                      </p>
                    </div>
                    <button
                      v-if="auditHasFixableIssues"
                      @click="fixAllAuditFromModal"
                      :disabled="auditFixingAll"
                      class="btn btn-primary btn-xs shrink-0"
                    >
                      <span v-if="auditFixingAll" class="spinner w-3.5 h-3.5"></span>
                      <span v-else class="material-symbols-rounded text-sm">build</span>
                      {{ auditFixingAll ? 'Fixing...' : 'Fix All' }}
                    </button>
                  </div>

                  <!-- Audit Checks by Category -->
                  <div v-for="(group, catKey) in auditChecksByCategory" :key="catKey" class="space-y-1">
                    <p class="text-xs font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400 px-1">
                      {{ group.label }}
                    </p>
                    <div class="bg-surface-50 dark:bg-surface-700/30 rounded-xl overflow-hidden divide-y divide-surface-200 dark:divide-surface-700">
                      <div 
                        v-for="(check, idx) in group.items" 
                        :key="idx"
                        class="flex items-center gap-3 px-3 py-2"
                      >
                        <span :class="['material-symbols-rounded text-lg', getAuditStatusColor(check.status)]">
                          {{ getAuditStatusIcon(check.status) }}
                        </span>
                        <div class="flex-1 min-w-0">
                          <p class="text-sm text-surface-800 dark:text-surface-200 truncate">{{ check.name }}</p>
                          <p v-if="check.detail && check.status !== 'pass'" class="text-xs text-surface-500 dark:text-surface-400 mt-0.5">
                            {{ check.detail }}
                          </p>
                        </div>
                        <button
                          v-if="check.fix_action && check.status !== 'pass'"
                          @click="fixAuditCheckFromModal(check)"
                          :disabled="auditFixingCheck === check.name"
                          class="inline-flex items-center gap-0.5 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-primary-500/10 text-primary-600 dark:text-primary-400 hover:bg-primary-500/20 transition-colors shrink-0"
                        >
                          <span v-if="auditFixingCheck === check.name" class="spinner w-3 h-3"></span>
                          <span v-else class="material-symbols-rounded text-xs">build</span>
                          Fix
                        </button>
                        <span :class="[
                          'px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase',
                          check.status === 'pass' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' :
                          check.status === 'fail' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' :
                          'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
                        ]">
                          {{ check.status }}
                        </span>
                      </div>
                    </div>
                  </div>
                </template>
              </div>
            </div>
          </template>

          <!-- Loading -->
          <div v-else-if="loading" class="flex items-center justify-center py-12">
            <div class="spinner w-8 h-8"></div>
          </div>

          <template v-else>
            <!-- Deployment Type -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-3">Deployment Type</label>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <button
                  v-for="dtype in deploymentTypes.filter(t => ['full_provision', 'config_only', 'packages_config', 'app_update'].includes(t.type))"
                  :key="dtype.type"
                  @click="selectedType = dtype.type"
                  :class="[
                    'flex items-start gap-3 p-4 rounded-xl text-left transition-all',
                    selectedType === dtype.type
                      ? 'bg-primary-500/20 border-2 border-primary-500'
                      : 'bg-surface-100 dark:bg-surface-700/50 border-2 border-transparent hover:border-surface-300 dark:hover:border-surface-600'
                  ]"
                >
                  <span :class="[
                    'material-symbols-rounded text-2xl',
                    selectedType === dtype.type ? 'text-primary-600 dark:text-primary-400' : 'text-surface-500 dark:text-surface-400'
                  ]">
                    {{ getTypeIcon(dtype.type) }}
                  </span>
                  <div class="flex-1 min-w-0">
                    <p class="font-medium text-surface-900 dark:text-surface-100">{{ dtype.label }}</p>
                    <p class="text-xs text-surface-500 dark:text-surface-400 mt-1">{{ dtype.description }}</p>
                  </div>
                </button>
              </div>
            </div>

            <!-- App Selection (for app_update) -->
            <div v-if="selectedType === 'app_update'" class="space-y-3">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300">Select Apps to Update</label>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div 
                  v-for="(appInfo, appKey) in { panel: { icon: 'dashboard', name: 'Panel', desc: 'VPS Admin Panel' }, email: { icon: 'mail', name: 'Email App', desc: 'MailFlow' }, agent: { icon: 'smart_toy', name: 'Agent', desc: 'Fleet Agent' } }"
                  :key="appKey"
                  @click="selectedApps[appKey] = !selectedApps[appKey]"
                  :class="[
                    'flex items-center gap-3 p-4 rounded-xl cursor-pointer transition-all',
                    selectedApps[appKey] 
                      ? 'bg-primary-500/20 border-2 border-primary-500' 
                      : 'bg-surface-100 dark:bg-surface-700/50 border-2 border-transparent hover:border-surface-300 dark:hover:border-surface-600'
                  ]"
                >
                  <span :class="['material-symbols-rounded text-2xl', selectedApps[appKey] ? 'text-primary-500' : 'text-surface-400']">{{ appInfo.icon }}</span>
                  <div class="flex-1">
                    <p class="font-medium text-surface-900 dark:text-surface-100">{{ appInfo.name }}</p>
                    <p class="text-xs text-surface-500 dark:text-surface-400">{{ appInfo.desc }}</p>
                  </div>
                  <div :class="['w-5 h-5 rounded-full border-2 flex items-center justify-center', selectedApps[appKey] ? 'bg-primary-500 border-primary-500' : 'border-surface-400']">
                    <span v-if="selectedApps[appKey]" class="material-symbols-rounded text-white text-sm">check</span>
                  </div>
                </div>
              </div>
              <p v-if="!canDeploy" class="text-xs text-amber-600 dark:text-amber-400">
                <span class="material-symbols-rounded text-sm align-middle">warning</span>
                Select at least one app to update
              </p>
            </div>

            <!-- Blueprint Selection -->
            <div v-if="requiresBlueprint">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Blueprint</label>
              <select 
                v-model="selectedBlueprint"
                class="input w-full"
              >
                <option :value="null" disabled>Select a blueprint...</option>
                <option v-for="bp in blueprints" :key="bp.id" :value="bp.id">
                  {{ bp.name }} ({{ bp.template_count }} templates)
                </option>
              </select>
              <p v-if="!selectedBlueprint" class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                <span class="material-symbols-rounded text-sm align-middle">warning</span>
                Blueprint is required for this deployment type
              </p>
            </div>

            <!-- Options -->
            <div class="space-y-3">
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">Options</label>
              
              <label class="flex items-center gap-3 cursor-pointer group">
                <button 
                  type="button"
                  role="switch"
                  :aria-checked="backup"
                  @click="backup = !backup"
                  :class="[
                    'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200',
                    backup ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                  ]"
                >
                  <span 
                    :class="[
                      'pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-sm transform transition-transform duration-200 mt-0.5',
                      backup ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
                    ]"
                  ></span>
                </button>
                <div>
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100">Backup existing configs</p>
                  <p class="text-xs text-surface-500 dark:text-surface-400">Save current configs before deploying (enables rollback)</p>
                </div>
              </label>
            </div>

            <!-- Preview Section (config_only) -->
            <div v-if="previewData" class="space-y-3">
              <div class="flex items-center justify-between">
                <h3 class="text-sm font-medium text-surface-700 dark:text-surface-300">Preview</h3>
                <button @click="previewData = null" class="text-xs text-surface-500 dark:text-surface-400 hover:text-surface-900 dark:hover:text-white">
                  Clear
                </button>
              </div>
              
              <div class="bg-surface-100 dark:bg-surface-900 rounded-xl p-4 space-y-3">
                <div class="grid grid-cols-3 gap-4 text-center">
                  <div>
                    <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ previewData.summary?.to_update || 0 }}</p>
                    <p class="text-xs text-surface-500 dark:text-surface-400">To Update</p>
                  </div>
                  <div>
                    <p class="text-2xl font-bold text-surface-500 dark:text-surface-400">{{ previewData.summary?.unchanged || 0 }}</p>
                    <p class="text-xs text-surface-500 dark:text-surface-400">Unchanged</p>
                  </div>
                  <div>
                    <p class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ previewData.summary?.services_to_restart?.length || 0 }}</p>
                    <p class="text-xs text-surface-500 dark:text-surface-400">Services</p>
                  </div>
                </div>

                <div v-if="previewData.summary?.services_to_restart?.length" class="pt-3 border-t border-surface-200 dark:border-surface-700">
                  <p class="text-xs text-surface-500 dark:text-surface-400 mb-2">Services to restart:</p>
                  <div class="flex flex-wrap gap-2">
                    <span 
                      v-for="svc in previewData.summary.services_to_restart" 
                      :key="svc"
                      class="badge badge-warning"
                    >
                      {{ svc }}
                    </span>
                  </div>
                </div>

                <div v-if="previewData.changes?.to_update?.length" class="pt-3 border-t border-surface-200 dark:border-surface-700">
                  <p class="text-xs text-surface-500 dark:text-surface-400 mb-2">Files to update:</p>
                  <div class="max-h-32 overflow-y-auto space-y-1">
                    <p 
                      v-for="t in previewData.changes.to_update" 
                      :key="t.target_path"
                      class="text-xs font-mono text-surface-700 dark:text-surface-300"
                    >
                      {{ t.target_path }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Preflight Results Section (full_provision / packages_config) -->
            <div v-if="preflightData" class="space-y-3">
              <div class="flex items-center justify-between">
                <h3 class="text-sm font-medium text-surface-700 dark:text-surface-300">Preflight Results</h3>
                <button @click="preflightData = null; preflightExpanded = null" class="text-xs text-surface-500 dark:text-surface-400 hover:text-surface-900 dark:hover:text-white">
                  Clear
                </button>
              </div>

              <!-- Summary bar -->
              <div :class="[
                'rounded-xl p-4 space-y-3',
                preflightData.summary?.can_proceed
                  ? 'bg-green-50 dark:bg-green-900/10 border border-green-200 dark:border-green-800'
                  : 'bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800'
              ]">
                <div class="flex items-center gap-3">
                  <span :class="[
                    'material-symbols-rounded text-2xl',
                    preflightData.summary?.can_proceed ? 'text-green-500' : 'text-red-500'
                  ]">
                    {{ preflightData.summary?.can_proceed ? 'verified' : 'gpp_bad' }}
                  </span>
                  <div class="flex-1">
                    <p class="font-medium text-surface-900 dark:text-surface-100">
                      {{ preflightData.summary?.can_proceed ? 'Ready to deploy' : 'Cannot deploy -- critical issues found' }}
                    </p>
                    <p class="text-xs text-surface-500 dark:text-surface-400 mt-0.5">
                      Completed in {{ formatDuration(preflightData.summary?.duration_ms) }}
                    </p>
                  </div>
                </div>

                <div class="grid grid-cols-3 gap-3 text-center pt-2 border-t border-surface-200/50 dark:border-surface-700/50">
                  <div>
                    <p class="text-xl font-bold text-green-600 dark:text-green-400">{{ preflightData.summary?.passed || 0 }}</p>
                    <p class="text-[11px] text-surface-500 dark:text-surface-400">Passed</p>
                  </div>
                  <div>
                    <p class="text-xl font-bold text-amber-600 dark:text-amber-400">{{ preflightData.summary?.warnings || 0 }}</p>
                    <p class="text-[11px] text-surface-500 dark:text-surface-400">Warnings</p>
                  </div>
                  <div>
                    <p class="text-xl font-bold text-red-600 dark:text-red-400">{{ preflightData.summary?.failed || 0 }}</p>
                    <p class="text-[11px] text-surface-500 dark:text-surface-400">Failed</p>
                  </div>
                </div>
              </div>

              <!-- Individual check results -->
              <div class="space-y-0 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
                <div
                  v-for="(check, idx) in preflightData.checks"
                  :key="check.key"
                  :class="[
                    'border-b border-surface-200 dark:border-surface-700 last:border-b-0',
                    getPreflightBgColor(check.status)
                  ]"
                >
                  <div
                    class="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-surface-50/50 dark:hover:bg-surface-700/20 transition-colors"
                    @click="preflightExpanded = preflightExpanded === check.key ? null : check.key"
                  >
                    <span
                      class="material-symbols-rounded text-xl shrink-0"
                      :class="getPreflightColor(check.status)"
                    >{{ getPreflightIcon(check.status) }}</span>

                    <div class="flex-1 min-w-0">
                      <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ check.name }}</span>
                        <span
                          class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[9px] font-semibold uppercase tracking-wider"
                          :class="getCategoryBadge(check.category)"
                        >{{ getCategoryLabel(check.category) }}</span>
                      </div>
                      <p class="text-xs text-surface-500 dark:text-surface-400 mt-0.5 truncate">{{ check.message }}</p>
                    </div>

                    <span class="text-[11px] text-surface-400 dark:text-surface-500 shrink-0 tabular-nums">
                      {{ formatDuration(check.duration_ms) }}
                    </span>

                    <span
                      class="material-symbols-rounded text-lg text-surface-400 dark:text-surface-500 shrink-0 transition-transform"
                      :class="{ 'rotate-180': preflightExpanded === check.key }"
                    >expand_more</span>
                  </div>

                  <!-- Expanded details -->
                  <div
                    v-if="preflightExpanded === check.key && check.details && Object.keys(check.details).length"
                    class="px-4 pb-3 ml-9"
                  >
                    <div class="bg-surface-100 dark:bg-surface-900 rounded-lg p-3 text-xs space-y-2">
                      <!-- DNS domains detail -->
                      <template v-if="check.key === 'dns_resolution' && check.details.domains">
                        <div v-for="(info, domain) in check.details.domains" :key="domain" class="flex items-center gap-2">
                          <span
                            class="material-symbols-rounded text-sm"
                            :class="info.match ? 'text-green-500' : 'text-red-500'"
                          >{{ info.match ? 'check' : 'close' }}</span>
                          <span class="font-mono text-surface-700 dark:text-surface-300">{{ domain }}</span>
                          <span class="text-surface-400">-></span>
                          <span :class="info.match ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'" class="font-mono">
                            {{ info.resolved }}
                          </span>
                          <span v-if="!info.match" class="text-surface-400">(expected {{ info.expected }})</span>
                        </div>
                      </template>

                      <!-- Disk space detail -->
                      <template v-else-if="check.key === 'disk_space' && check.details.partitions_mb">
                        <div v-for="(mb, mount) in check.details.partitions_mb" :key="mount" class="flex items-center gap-2">
                          <span class="font-mono text-surface-700 dark:text-surface-300 w-16">{{ mount }}</span>
                          <div class="flex-1 h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                            <div
                              class="h-full rounded-full"
                              :class="mb < 5120 ? 'bg-red-500' : 'bg-green-500'"
                              :style="{ width: Math.min(100, (mb / 51200) * 100) + '%' }"
                            ></div>
                          </div>
                          <span :class="mb < 5120 ? 'text-red-600 dark:text-red-400' : 'text-surface-600 dark:text-surface-300'" class="tabular-nums w-20 text-right">
                            {{ (mb / 1024).toFixed(1) }} GB free
                          </span>
                        </div>
                      </template>

                      <!-- Port availability detail -->
                      <template v-else-if="check.key === 'port_availability' && check.details.occupied">
                        <div v-for="(info, port) in check.details.occupied" :key="port" class="flex items-center gap-2">
                          <span class="material-symbols-rounded text-sm text-amber-500">warning</span>
                          <span class="font-mono text-surface-700 dark:text-surface-300">:{{ port }}</span>
                          <span class="text-surface-500">{{ info.label }}</span>
                          <span class="text-surface-400">-- held by</span>
                          <span class="font-mono text-amber-600 dark:text-amber-400">{{ info.process }}</span>
                        </div>
                      </template>

                      <!-- Package files detail -->
                      <template v-else-if="check.key === 'package_files' && (check.details.packages || check.details.found)">
                        <div v-for="(info, type) in (check.details.packages || check.details.found)" :key="type" class="flex items-center gap-2">
                          <span class="material-symbols-rounded text-sm text-green-500">check</span>
                          <span class="font-medium text-surface-700 dark:text-surface-300 capitalize">{{ type }}</span>
                          <span class="text-surface-400">v{{ info.version }}</span>
                          <span class="text-surface-400">({{ info.size_mb }} MB)</span>
                        </div>
                        <div v-if="check.details.missing?.length" v-for="type in check.details.missing" :key="'m-'+type" class="flex items-center gap-2">
                          <span class="material-symbols-rounded text-sm text-red-500">close</span>
                          <span class="font-medium text-red-600 dark:text-red-400 capitalize">{{ type }}</span>
                          <span class="text-red-500">missing</span>
                        </div>
                      </template>

                      <!-- System resources detail -->
                      <template v-else-if="check.key === 'system_resources'">
                        <div class="grid grid-cols-3 gap-3 text-center">
                          <div>
                            <p class="font-bold text-surface-700 dark:text-surface-200">{{ check.details.cpu_cores || '?' }}</p>
                            <p class="text-surface-400">CPU Cores</p>
                          </div>
                          <div>
                            <p class="font-bold text-surface-700 dark:text-surface-200">{{ check.details.total_ram_mb || '?' }} MB</p>
                            <p class="text-surface-400">Total RAM</p>
                          </div>
                          <div>
                            <p :class="(check.details.available_ram_mb || 0) < 768 ? 'font-bold text-red-600 dark:text-red-400' : 'font-bold text-surface-700 dark:text-surface-200'">
                              {{ check.details.available_ram_mb || '?' }} MB
                            </p>
                            <p class="text-surface-400">Available RAM</p>
                          </div>
                        </div>
                      </template>

                      <!-- Existing services detail -->
                      <template v-else-if="check.key === 'existing_services' && check.details.active?.length">
                        <div class="flex flex-wrap gap-1.5">
                          <span
                            v-for="svc in check.details.active"
                            :key="svc"
                            class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-[10px] font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                          >
                            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                            {{ svc }}
                          </span>
                        </div>
                      </template>

                      <!-- Internet access detail -->
                      <template v-else-if="check.key === 'internet_access'">
                        <div v-if="check.details.reachable" v-for="(code, name) in check.details.reachable" :key="name" class="flex items-center gap-2">
                          <span class="material-symbols-rounded text-sm text-green-500">check</span>
                          <span class="text-surface-700 dark:text-surface-300">{{ name }}</span>
                          <span class="text-surface-400">HTTP {{ code }}</span>
                        </div>
                        <div v-if="check.details.unreachable" v-for="(reason, name) in check.details.unreachable" :key="name" class="flex items-center gap-2">
                          <span class="material-symbols-rounded text-sm text-red-500">close</span>
                          <span class="text-surface-700 dark:text-surface-300">{{ name }}</span>
                          <span class="text-red-500">{{ reason }}</span>
                        </div>
                      </template>

                      <!-- Generic fallback -->
                      <template v-else>
                        <pre class="text-[11px] text-surface-600 dark:text-surface-400 whitespace-pre-wrap font-mono">{{ JSON.stringify(check.details, null, 2) }}</pre>
                      </template>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-between px-6 py-4 border-t border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/80 rounded-b-2xl">
          <!-- Progress Footer -->
          <template v-if="showProgress">
            <div class="flex items-center gap-2 text-sm text-surface-500 dark:text-surface-400">
              <span class="material-symbols-rounded text-lg">tag</span>
              Deploy #{{ deploymentId }}
              <span v-if="deploymentPid" class="text-xs">(PID {{ deploymentPid }})</span>
            </div>
            <div class="flex items-center gap-2">
              <!-- Resume buttons (when failed) -->
              <template v-if="canResume">
                <button 
                  @click="resumeDeployment(false)"
                  :disabled="resuming"
                  class="btn btn-primary btn-sm"
                >
                  <span v-if="resuming" class="spinner w-4 h-4"></span>
                  <span v-else class="material-symbols-rounded">replay</span>
                  Retry Failed Step
                </button>
                <button 
                  @click="resumeDeployment(true)"
                  :disabled="resuming"
                  class="btn btn-secondary btn-sm"
                >
                  <span class="material-symbols-rounded">skip_next</span>
                  Skip &amp; Continue
                </button>
              </template>

              <!-- Cancel button -->
              <button 
                v-if="deploymentStatus === 'running' || deploymentStatus === 'pending'"
                @click="cancelDeployment"
                class="btn btn-ghost text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/30 btn-sm"
              >
                <span class="material-symbols-rounded">cancel</span>
                Cancel
              </button>

              <!-- Done button -->
              <button 
                v-if="['success', 'cancelled'].includes(deploymentStatus)"
                @click="closeProgress"
                class="btn btn-primary btn-sm"
              >
                <span class="material-symbols-rounded">check</span>
                Done
              </button>
              <!-- Close button for failed (after resume options) -->
              <button 
                v-if="deploymentStatus === 'failed'"
                @click="closeProgress"
                class="btn btn-ghost btn-sm"
              >
                Close
              </button>
            </div>
          </template>

          <!-- Normal Footer -->
          <template v-else>
            <button 
              v-if="selectedType === 'config_only'"
              @click="preview" 
              :disabled="!canDeploy || previewing"
              class="btn btn-secondary"
            >
              <span v-if="previewing" class="spinner w-4 h-4"></span>
              <span v-else class="material-symbols-rounded">preview</span>
              Preview Changes
            </button>
            <button
              v-else-if="preflightAvailable"
              @click="runPreflight"
              :disabled="preflightRunning"
              class="btn btn-secondary"
            >
              <span v-if="preflightRunning" class="spinner w-4 h-4"></span>
              <span v-else class="material-symbols-rounded">flight_takeoff</span>
              {{ preflightData ? 'Re-run Preflight' : 'Run Preflight' }}
            </button>
            <div v-else class="text-sm text-surface-500 dark:text-surface-400 flex items-center gap-2">
              <span class="material-symbols-rounded text-lg">info</span>
              Preview not available for this deployment type
            </div>
            
            <div class="flex items-center gap-3">
              <button @click="close" class="btn btn-ghost">
                Cancel
              </button>
              <div class="flex flex-col items-end gap-1">
                <button 
                  @click="deploy" 
                  :disabled="!canDeploy || deploying"
                  class="btn btn-primary"
                >
                  <span v-if="deploying" class="spinner w-4 h-4"></span>
                  <span v-else class="material-symbols-rounded">rocket_launch</span>
                  Deploy
                </button>
                <p v-if="preflightData && !preflightData.summary?.can_proceed" class="text-[11px] text-red-500 dark:text-red-400">
                  Fix critical issues to deploy
                </p>
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
</style>
