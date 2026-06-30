<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import api from '../services/api'
import { useToastStore } from '../stores/toast'

const router = useRouter()
const route = useRoute()
const toast = useToastStore()

// Form state
const step = ref(1)
const connectionForm = ref({
  is_local: true, // true = this server, false = remote server
  ip_address: '127.0.0.1',
  ssh_port: 22,
  ssh_user: 'root',
  auth_method: 'key', // 'key' or 'password'
  ssh_password: '',
  key_path: '/root/.ssh/id_rsa',
  key_passphrase: ''
})

// Snapshot-based flow
const useSnapshotFlow = ref(true) // default to snapshot for local server
const availableSnapshots = ref([])
const selectedSnapshotId = ref(null)
const selectedSnapshot = ref(null)
const snapshotLoading = ref(false)
const takingSnapshot = ref(false)
const deletingSnapshot = ref(null)

// Load snapshots on mount, auto-select if came from dashboard
onMounted(async () => {
  await fetchSnapshots()
  if (route.query.snapshot) {
    selectedSnapshotId.value = route.query.snapshot
    await loadSnapshot(route.query.snapshot)
    step.value = 2
  }
})

const fetchSnapshots = async () => {
  try {
    const response = await api.get('/api/system/snapshots')
    availableSnapshots.value = response.data || []
  } catch (e) {
    // non-critical
  }
}

const deleteSnapshot = async (snap) => {
  if (!confirm(`Delete snapshot "${snap.label || snap.id}"? This cannot be undone.`)) {
    return
  }
  deletingSnapshot.value = snap.id
  try {
    await api.delete('/api/system/snapshots/' + snap.id)
    availableSnapshots.value = availableSnapshots.value.filter(s => s.id !== snap.id)
    if (selectedSnapshotId.value === snap.id) {
      selectedSnapshotId.value = null
      selectedSnapshot.value = null
    }
    toast.success('Snapshot deleted')
  } catch (error) {
    toast.error('Failed to delete snapshot: ' + (error.response?.data?.error || error.message || 'Unknown error'))
  } finally {
    deletingSnapshot.value = null
  }
}

const takeNewSnapshot = async () => {
  takingSnapshot.value = true
  addDebugLog('Taking new server snapshot...', 'info')
  try {
    const response = await api.post('/api/system/snapshots', {
      mode: extractionMode.value,
    })
    addDebugLog('Snapshot complete!', 'success', response.data)
    toast.success('Snapshot taken')
    await fetchSnapshots()
    // Auto-select the new snapshot
    if (response.data.snapshot_id) {
      selectedSnapshotId.value = response.data.snapshot_id
      await loadSnapshot(response.data.snapshot_id)
    }
  } catch (error) {
    addDebugLog('Snapshot failed: ' + (error.message || 'Unknown'), 'error')
    toast.error(error.message || 'Snapshot failed')
  } finally {
    takingSnapshot.value = false
  }
}

const loadSnapshot = async (id) => {
  snapshotLoading.value = true
  addDebugLog('Loading snapshot ' + id + '...', 'info')
  try {
    const response = await api.get('/api/system/snapshots/' + id)
    selectedSnapshot.value = response.data
    selectedSnapshotId.value = id

    // Populate extraction result from snapshot for step 3
    extractionResult.value = {
      success: true,
      dry_run: false,
      server_info: response.data.server_info,
      installed_services: response.data.installed_services,
      extracted: response.data.extracted,
      skipped: {},
      errors: {},
      summary: response.data.summary,
    }

    // Auto-populate blueprint name
    const hostname = response.data.server_info?.hostname || 'server'
    blueprintForm.value.name = 'Blueprint from ' + hostname
    blueprintForm.value.description = 'Generated from snapshot ' + id + ' on ' + new Date().toLocaleDateString()

    addDebugLog('Snapshot loaded: ' + (response.data.categories_count || 0) + ' categories', 'success')
  } catch (error) {
    addDebugLog('Failed to load snapshot: ' + error.message, 'error')
    toast.error('Failed to load snapshot')
  } finally {
    snapshotLoading.value = false
  }
}

// Template preview (dynamically generated from snapshot)
const templatePreview = ref(null)
const previewingTemplates = ref(false)
const previewExpanded = ref({}) // category => boolean

const previewTemplatesFromSnapshot = async () => {
  if (!selectedSnapshotId.value) {
    toast.error('No snapshot selected')
    return
  }

  previewingTemplates.value = true
  addDebugLog('Generating template preview from snapshot...', 'info')

  try {
    const response = await api.post('/api/system/snapshots/' + selectedSnapshotId.value + '/preview-templates', {
      categories: null, // all categories
    })

    templatePreview.value = response.data
    addDebugLog('Preview generated: ' + (response.data.stats?.total_templates || 0) + ' templates, ' + (response.data.stats?.total_variables || 0) + ' variables detected', 'success')
    toast.success('Template preview generated')
  } catch (error) {
    addDebugLog('Preview failed: ' + (error.message || 'Unknown'), 'error')
    toast.error(error.message || 'Preview failed')
  } finally {
    previewingTemplates.value = false
  }
}

const togglePreviewCategory = (category) => {
  previewExpanded.value[category] = !previewExpanded.value[category]
}

const saveBlueprintFromSnapshot = async () => {
  if (!blueprintForm.value.name) {
    toast.error('Please enter a blueprint name')
    return
  }
  if (!selectedSnapshotId.value) {
    toast.error('No snapshot selected')
    return
  }

  saving.value = true
  addDebugLog('Creating blueprint from snapshot (templates generated dynamically from real server configs)...', 'info')

  try {
    const response = await api.post('/api/system/snapshots/' + selectedSnapshotId.value + '/create-blueprint', {
      name: blueprintForm.value.name,
      description: blueprintForm.value.description,
      categories: null, // use all
    })

    addDebugLog('Blueprint created!', 'success', response.data)
    toast.success('Blueprint created with ' + (response.data.template_count || 0) + ' dynamically generated templates')
    router.push('/blueprints/' + response.data.blueprint_id)
  } catch (error) {
    addDebugLog('Failed: ' + (error.message || 'Unknown'), 'error')
    toast.error(error.message || 'Failed to create blueprint')
  } finally {
    saving.value = false
  }
}

const blueprintForm = ref({
  name: '',
  description: '',
  variables: {}
})

// Detected variables from extraction
const detectedVariables = ref({
  detected: {},
  definitions: [],
  found_in: {}
})

// Variable categories for grouping UI
const variableCategories = ref([
  { key: 'server', label: 'Server', icon: 'dns' },
  { key: 'domains', label: 'Domains', icon: 'language' },
  { key: 'mail_database', label: 'Mail Database', icon: 'mail' },
  { key: 'panel_database', label: 'Panel Database', icon: 'dashboard' },
  { key: 'email_database', label: 'Email App Database', icon: 'inbox' },
  { key: 'ssl', label: 'SSL/TLS', icon: 'lock' },
  { key: 'dkim', label: 'DKIM', icon: 'verified_user' },
  { key: 'admin', label: 'Admin', icon: 'admin_panel_settings' },
  { key: 'database', label: 'Database', icon: 'storage' }
])

// Track which variable groups are expanded
const expandedCategories = ref(['server', 'domains', 'mail_database'])

// Edit mode for variables
const editingVariable = ref(null)

// Toggle variable category expansion
const toggleCategory = (categoryKey) => {
  const idx = expandedCategories.value.indexOf(categoryKey)
  if (idx >= 0) {
    expandedCategories.value.splice(idx, 1)
  } else {
    expandedCategories.value.push(categoryKey)
  }
}

// Get variables by category
const getVariablesByCategory = (categoryKey) => {
  return detectedVariables.value.definitions.filter(v => v.category === categoryKey)
}

// Update a variable value
const updateVariableValue = (varName, value) => {
  blueprintForm.value.variables[varName] = value
  // Also update the detected value display
  if (detectedVariables.value.detected) {
    detectedVariables.value.detected[varName] = value
  }
}

// Generate a random password
const generatePassword = (varName, length = 24) => {
  const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*'
  let password = ''
  for (let i = 0; i < length; i++) {
    password += chars.charAt(Math.floor(Math.random() * chars.length))
  }
  updateVariableValue(varName, password)
}

// Check if a variable has a detected value
const hasDetectedValue = (varName) => {
  return detectedVariables.value.detected?.[varName] !== null && 
         detectedVariables.value.detected?.[varName] !== undefined
}

// Get display value for a variable
const getVariableDisplayValue = (varDef) => {
  // Check user override first
  if (blueprintForm.value.variables[varDef.name]) {
    return blueprintForm.value.variables[varDef.name]
  }
  // Then check detected value
  if (detectedVariables.value.detected?.[varDef.name]) {
    return detectedVariables.value.detected[varDef.name]
  }
  // Then check default
  if (varDef.default) {
    return varDef.default
  }
  return ''
}

// Mask password for display
const maskPassword = (value) => {
  if (!value) return ''
  if (value.length <= 4) return '****'
  return value.substring(0, 2) + '****' + value.substring(value.length - 2)
}

// Format variable name as template placeholder
const formatVarPlaceholder = (varName) => {
  return '{{' + varName + '}}'
}

// Extraction mode settings
const extractionMode = ref('full_clone') // 'full_clone' or 'base_config'
const includeCoreApps = ref(['panel', 'emailapp', 'fleetmanager'])
const selectedVhosts = ref([]) // Additional vhosts to include in base_config mode
const availableVhosts = ref([]) // Populated from server
const categoryDetails = ref({}) // Category type info from server

// Status states
const testing = ref(false)
const connectionTested = ref(false)
const connectionInfo = ref(null)

const extracting = ref(false)
const extractionResult = ref(null)

const saving = ref(false)

// Debug panel
const debugExpanded = ref(true)
const debugLogs = ref([])
const autoScroll = ref(true)

// Format variable name with curly brace wrappers (avoids Vue template parser issues)
const wrapVar = (v) => `{{${v}}}`

// Add debug log entry
const addDebugLog = (message, level = 'info', data = null) => {
  const entry = {
    id: Date.now() + Math.random(),
    timestamp: new Date().toLocaleTimeString(),
    level,
    message,
    data,
    expanded: false
  }
  debugLogs.value.push(entry)
  
  // Auto scroll to bottom
  if (autoScroll.value) {
    setTimeout(() => {
      const container = document.getElementById('debug-container')
      if (container) {
        container.scrollTop = container.scrollHeight
      }
    }, 50)
  }
}

// Clear debug logs
const clearDebugLogs = () => {
  debugLogs.value = []
}

// Test connection
const testConnection = async () => {
  // Local server - auto-configure for localhost SSH
  if (connectionForm.value.is_local) {
    connectionForm.value.ip_address = '127.0.0.1'
    connectionForm.value.ssh_user = 'root'
    connectionForm.value.key_path = '/root/.ssh/id_rsa'
  }

  if (!connectionForm.value.ip_address) {
    toast.error('Please enter IP address')
    return
  }
  if (!connectionForm.value.ssh_user) {
    toast.error('Please enter SSH user')
    return
  }
  if (connectionForm.value.auth_method === 'password' && !connectionForm.value.ssh_password) {
    toast.error('Please enter SSH password')
    return
  }
  if (connectionForm.value.auth_method === 'key' && !connectionForm.value.key_path) {
    toast.error('Please enter SSH key path')
    return
  }

  testing.value = true
  connectionTested.value = false
  connectionInfo.value = null
  
  if (connectionForm.value.is_local) {
    addDebugLog('Testing agent connection...', 'info', {
      mode: 'local',
      agent_socket: '/run/fleet-manager/agent.sock'
    })
  } else {
    addDebugLog('Testing SSH connection...', 'info', {
      ip: connectionForm.value.ip_address,
      port: connectionForm.value.ssh_port,
      user: connectionForm.value.ssh_user
    })
  }

  try {
    const response = await api.post('/api/blueprints/test-connection', connectionForm.value)
    
    connectionTested.value = true
    connectionInfo.value = response.data
    
    addDebugLog('Connection successful!', 'success', response.data)
    toast.success('Connection successful')
  } catch (error) {
    addDebugLog('Connection failed: ' + (error.message || 'Unknown error'), 'error')
    toast.error(error.message || 'Connection failed')
  } finally {
    testing.value = false
  }
}

// Run dry run extraction
const runDryRun = async () => {
  extracting.value = true
  extractionResult.value = null
  
  addDebugLog('Starting DRY RUN extraction...', 'info')
  addDebugLog('This will show what would be collected without actually doing it', 'debug')

  try {
    const response = await api.post('/api/blueprints/extract', {
      ...connectionForm.value,
      dry_run: true
    })
    
    extractionResult.value = response.data
    
    // Add logs from extraction
    if (response.data.log) {
      response.data.log.forEach(entry => {
        addDebugLog(entry.message, entry.level, entry.data)
      })
    }
    
    addDebugLog('Dry run complete', 'success', response.data.summary)
  } catch (error) {
    addDebugLog('Dry run failed: ' + (error.message || 'Unknown error'), 'error')
    toast.error(error.message || 'Dry run failed')
  } finally {
    extracting.value = false
  }
}

// Run actual extraction (chunked to avoid timeouts)
const runExtraction = async () => {
  extracting.value = true
  extractionResult.value = null
  
  addDebugLog('Starting ACTUAL extraction (chunked)...', 'warning')
  addDebugLog('Fetching extraction categories...', 'info')

  try {
    // Step 1: Get categories and chunks (pass is_local to use agent categories)
    const categoriesResponse = await api.get('/api/blueprints/categories', {
      params: { 
        is_local: connectionForm.value.is_local,
        mode: extractionMode.value
      }
    })
    const { chunks, total, available_vhosts, category_details } = categoriesResponse.data
    
    // Store available vhosts and category details
    if (available_vhosts) {
      availableVhosts.value = available_vhosts
    }
    if (category_details) {
      categoryDetails.value = category_details
    }
    
    addDebugLog(`Found ${total} categories in ${chunks.length} chunks`, 'info')
    addDebugLog(`Extraction mode: ${extractionMode.value}`, 'info')
    
    // Initialize merged result
    let mergedResult = {
      success: true,
      dry_run: false,
      server_info: null,
      installed_services: {},
      extracted: {},
      skipped: {},
      errors: {},
      summary: { total_files: 0, total_size: 0, categories_extracted: 0, categories_skipped: 0, errors_count: 0, categories: {} }
    }
    
    // Step 2: Extract each chunk
    for (let i = 0; i < chunks.length; i++) {
      const chunk = chunks[i]
      addDebugLog(`Extracting chunk ${i + 1}/${chunks.length}: ${chunk.names.join(', ')}`, 'info')
      
      const response = await api.post('/api/blueprints/extract', {
        ...connectionForm.value,
        dry_run: false,
        categories: chunk.categories,
        mode: extractionMode.value,
        include_core_apps: includeCoreApps.value,
        selected_vhosts: selectedVhosts.value
      })
      
      const chunkData = response.data
      
      // Merge server info (only from first chunk)
      if (i === 0 && chunkData.server_info) {
        mergedResult.server_info = chunkData.server_info
      }
      
      // Merge installed services
      mergedResult.installed_services = { ...mergedResult.installed_services, ...chunkData.installed_services }
      
      // Merge extracted configs
      mergedResult.extracted = { ...mergedResult.extracted, ...chunkData.extracted }
      
      // Merge skipped
      mergedResult.skipped = { ...mergedResult.skipped, ...chunkData.skipped }
      
      // Merge errors
      mergedResult.errors = { ...mergedResult.errors, ...chunkData.errors }
      
      // Update summary
      if (chunkData.summary) {
        mergedResult.summary.total_files += chunkData.summary.total_files || 0
        mergedResult.summary.total_size += chunkData.summary.total_size || 0
        mergedResult.summary.categories_extracted += chunkData.summary.categories_extracted || 0
        mergedResult.summary.categories_skipped += chunkData.summary.categories_skipped || 0
        mergedResult.summary.errors_count += chunkData.summary.errors_count || 0
        mergedResult.summary.categories = { ...mergedResult.summary.categories, ...chunkData.summary.categories }
      }
      
      // Log chunk completion
      const chunkFiles = chunkData.summary?.total_files || Object.keys(chunkData.extracted || {}).length
      addDebugLog(`Chunk ${i + 1} complete: ${chunkFiles} files extracted`, 'success')
      
      // Add logs from extraction
      if (chunkData.log) {
        chunkData.log.forEach(entry => {
          if (entry.level !== 'debug') { // Skip debug logs to reduce noise
            addDebugLog(entry.message, entry.level, entry.data)
          }
        })
      }
    }
    
    // Recalculate summary totals
    mergedResult.summary.total_size_human = formatBytes(mergedResult.summary.total_size)
    
    extractionResult.value = mergedResult
    
    addDebugLog('All chunks extracted!', 'success', mergedResult.summary)
    
    // Auto-populate blueprint name
    if (mergedResult.server_info?.hostname) {
      blueprintForm.value.name = `Blueprint from ${mergedResult.server_info.hostname}`
      blueprintForm.value.description = `Extracted from ${mergedResult.server_info.hostname} (${mergedResult.server_info.ip}) on ${new Date().toLocaleDateString()}`
    }
    
    // Detect variables from extraction (call API to analyze)
    try {
      addDebugLog('Analyzing extracted configs for variables...', 'info')
      const variablesResponse = await api.post('/api/blueprints/detect-variables', {
        extracted_data: mergedResult
      })
      if (variablesResponse.data) {
        detectedVariables.value = variablesResponse.data
        addDebugLog(`Detected ${Object.keys(variablesResponse.data.detected || {}).length} variable values`, 'success')
        
        // Pre-populate variables form with detected values
        if (variablesResponse.data.detected) {
          for (const [key, value] of Object.entries(variablesResponse.data.detected)) {
            if (value) {
              blueprintForm.value.variables[key] = value
            }
          }
        }
      }
    } catch (varError) {
      addDebugLog('Variable detection skipped: ' + varError.message, 'warning')
    }
    
    // Move to next step
    step.value = 3
    toast.success(`Extraction complete! ${mergedResult.summary.total_files} files from ${mergedResult.summary.categories_extracted} categories`)
  } catch (error) {
    addDebugLog('Extraction failed: ' + (error.message || 'Unknown error'), 'error')
    toast.error(error.message || 'Extraction failed')
  } finally {
    extracting.value = false
  }
}

// Helper to format bytes
const formatBytes = (bytes) => {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

// Save blueprint
const saveBlueprint = async () => {
  if (!blueprintForm.value.name) {
    toast.error('Please enter a blueprint name')
    return
  }

  if (!extractionResult.value || extractionResult.value.dry_run) {
    toast.error('Please run actual extraction first')
    return
  }

  saving.value = true
  
  // Debug: Check payload size before sending
  const payload = {
    name: blueprintForm.value.name,
    description: blueprintForm.value.description,
    extracted_data: extractionResult.value,
    variables: blueprintForm.value.variables
  }
  const payloadJson = JSON.stringify(payload)
  const payloadSize = new Blob([payloadJson]).size
  addDebugLog('Saving blueprint...', 'info', {
    payload_size: payloadSize,
    payload_size_human: formatBytes(payloadSize)
  })
  console.log('Blueprint payload size:', payloadSize, 'bytes')

  try {
    const response = await api.post('/api/blueprints/create-from-extraction', payload)
    
    addDebugLog('Blueprint saved successfully!', 'success', {
      id: response.data.id,
      templates: response.data.template_count
    })
    
    toast.success(`Blueprint created with ${response.data.template_count} templates`)
    router.push(`/blueprints/${response.data.id}`)
  } catch (error) {
    addDebugLog('Failed to save blueprint: ' + (error.message || 'Unknown error'), 'error')
    toast.error(error.message || 'Failed to save blueprint')
  } finally {
    saving.value = false
  }
}

// Computed
const canProceedToStep2 = computed(() => connectionTested.value)
const canProceedToStep3 = computed(() => extractionResult.value && !extractionResult.value.dry_run)

const extractionSummary = computed(() => {
  if (!extractionResult.value?.summary) return null
  return extractionResult.value.summary
})

// Log level colors
const getLogLevelClass = (level) => {
  switch (level) {
    case 'success': return 'text-green-400'
    case 'error': return 'text-red-400'
    case 'warning': return 'text-amber-400'
    case 'debug': return 'text-purple-400'
    default: return 'text-blue-400'
  }
}

const getLogLevelIcon = (level) => {
  switch (level) {
    case 'success': return 'check_circle'
    case 'error': return 'error'
    case 'warning': return 'warning'
    case 'debug': return 'bug_report'
    default: return 'info'
  }
}

const formatSize = (bytes) => {
  if (!bytes) return '0 B'
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / 1048576).toFixed(1) + ' MB'
}

// File preview modal
const showFilePreview = ref(false)
const previewFile = ref(null)

const openFilePreview = (file) => {
  previewFile.value = file
  showFilePreview.value = true
}

const closeFilePreview = () => {
  showFilePreview.value = false
  previewFile.value = null
}

// Get all extracted files as flat list
const allExtractedFiles = computed(() => {
  if (!extractionResult.value?.extracted) return []
  
  const files = []
  for (const [category, data] of Object.entries(extractionResult.value.extracted)) {
    for (const file of (data.files || [])) {
      files.push({
        ...file,
        category: data.name || category
      })
    }
  }
  return files
})
</script>

<template>
  <div class="animate-fadeIn">
    <!-- Header -->
    <div class="flex items-center gap-4 mb-6">
      <button @click="router.push('/blueprints')" class="btn btn-ghost btn-sm">
        <span class="material-symbols-rounded">arrow_back</span>
      </button>
      <div>
        <h1 class="text-2xl font-bold">Create Blueprint from Server</h1>
        <p class="text-surface-400">Extract configuration from an existing server to create a reusable blueprint</p>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Main Content -->
      <div class="lg:col-span-2 space-y-6">
        <!-- Progress Steps -->
        <div class="flex items-center justify-center gap-2 mb-4">
          <div v-for="s in 3" :key="s" class="flex items-center">
            <div :class="[
              'w-10 h-10 rounded-full flex items-center justify-center font-medium transition-colors',
              step >= s ? 'bg-primary-500 text-white' : 'bg-surface-700 text-surface-400'
            ]">
              {{ s }}
            </div>
            <div v-if="s < 3" :class="['w-16 h-1 mx-2', step > s ? 'bg-primary-500' : 'bg-surface-700']"></div>
          </div>
        </div>

        <!-- Step 1: Connection -->
        <div v-show="step === 1" class="card">
          <div class="card-header">
            <h2 class="font-semibold">Step 1: Select Source Server</h2>
          </div>
          <div class="card-body space-y-4">
            <p class="text-muted text-sm">
              Choose the server you want to create a blueprint snapshot from.
            </p>

            <!-- Server Type Selection -->
            <div>
              <label class="block text-sm font-medium mb-3">Server Type</label>
              <div class="grid grid-cols-2 gap-3">
                <button
                  type="button"
                  @click="connectionForm.is_local = true; connectionForm.ip_address = '127.0.0.1'"
                  :class="[
                    'p-4 rounded-xl border-2 text-left transition-all',
                    connectionForm.is_local 
                      ? 'border-primary-500 bg-primary-500/10' 
                      : 'border-[rgb(var(--color-border))] hover:border-[rgb(var(--color-border-strong))]'
                  ]"
                >
                  <div class="flex items-center gap-3">
                    <span class="material-symbols-rounded text-2xl" :class="connectionForm.is_local ? 'text-primary-500' : 'text-muted'">computer</span>
                    <div>
                      <p class="font-medium" :class="connectionForm.is_local ? 'text-primary-600 dark:text-primary-400' : ''">This Server</p>
                      <p class="text-xs text-muted">Direct file access (localhost)</p>
                    </div>
                  </div>
                </button>
                <button
                  type="button"
                  @click="connectionForm.is_local = false; connectionForm.ip_address = ''; connectionTested = false; connectionInfo = null"
                  :class="[
                    'p-4 rounded-xl border-2 text-left transition-all',
                    !connectionForm.is_local 
                      ? 'border-primary-500 bg-primary-500/10' 
                      : 'border-[rgb(var(--color-border))] hover:border-[rgb(var(--color-border-strong))]'
                  ]"
                >
                  <div class="flex items-center gap-3">
                    <span class="material-symbols-rounded text-2xl" :class="!connectionForm.is_local ? 'text-primary-500' : 'text-muted'">cloud</span>
                    <div>
                      <p class="font-medium" :class="!connectionForm.is_local ? 'text-primary-600 dark:text-primary-400' : ''">Remote Server</p>
                      <p class="text-xs text-muted">Connect via SSH</p>
                    </div>
                  </div>
                </button>
              </div>
            </div>

            <!-- Local Server Info -->
            <div v-if="connectionForm.is_local" class="p-4 rounded-xl bg-primary-500/10 border border-primary-500/30">
              <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-primary-500 text-2xl">info</span>
                <div>
                  <p class="font-medium text-primary-600 dark:text-primary-400">This Server Selected</p>
                  <p class="text-sm text-muted">Uses the Fleet Manager agent daemon for secure access to all config files</p>
                </div>
              </div>
            </div>

            <!-- Remote Server SSH Fields -->
            <template v-if="!connectionForm.is_local">
              <div class="grid grid-cols-3 gap-4">
                <div class="col-span-2">
                  <label class="block text-sm font-medium mb-2">IP Address *</label>
                  <input v-model="connectionForm.ip_address" type="text" class="input w-full" placeholder="e.g., 192.168.1.100" />
                </div>
                <div>
                  <label class="block text-sm font-medium mb-2">SSH Port</label>
                  <input v-model="connectionForm.ssh_port" type="number" class="input w-full" />
                </div>
              </div>

              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium mb-2">SSH User *</label>
                  <input v-model="connectionForm.ssh_user" type="text" class="input w-full" placeholder="e.g., root" />
                </div>
                <div>
                  <label class="block text-sm font-medium mb-2">Auth Method</label>
                  <div class="flex items-center gap-4 h-[42px]">
                    <label class="flex items-center gap-2 cursor-pointer">
                      <button 
                        type="button"
                        @click="connectionForm.auth_method = 'key'"
                        :class="['toggle', { active: connectionForm.auth_method === 'key' }]"
                      >
                        <span class="toggle-dot"></span>
                      </button>
                      <span class="text-sm">SSH Key</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                      <button 
                        type="button"
                        @click="connectionForm.auth_method = 'password'"
                        :class="['toggle', { active: connectionForm.auth_method === 'password' }]"
                      >
                        <span class="toggle-dot"></span>
                      </button>
                      <span class="text-sm">Password</span>
                    </label>
                  </div>
                </div>
              </div>

              <!-- Key-based auth fields -->
              <div v-if="connectionForm.auth_method === 'key'" class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium mb-2">Key Path *</label>
                  <input v-model="connectionForm.key_path" type="text" class="input w-full" placeholder="/root/.ssh/id_rsa" />
                </div>
                <div>
                  <label class="block text-sm font-medium mb-2">Key Passphrase</label>
                  <input v-model="connectionForm.key_passphrase" type="password" class="input w-full" placeholder="Leave empty if none" />
                </div>
              </div>

              <!-- Password auth fields -->
              <div v-if="connectionForm.auth_method === 'password'">
                <label class="block text-sm font-medium mb-2">SSH Password *</label>
                <input v-model="connectionForm.ssh_password" type="password" class="input w-full" placeholder="Enter password" />
              </div>
            </template>

            <div class="flex items-center gap-4">
              <button @click="testConnection" :disabled="testing" class="btn btn-secondary">
                <span v-if="testing" class="spinner w-5 h-5"></span>
                <span v-else class="material-symbols-rounded">wifi_tethering</span>
                {{ testing ? 'Testing...' : 'Test Connection' }}
              </button>

              <div v-if="connectionTested" class="flex items-center gap-2 text-green-400">
                <span class="material-symbols-rounded">check_circle</span>
                <span>Connected to {{ connectionInfo?.hostname || 'server' }}</span>
              </div>
            </div>

            <div v-if="connectionInfo" class="bg-surface-700 rounded-lg p-4 text-sm">
              <div class="grid grid-cols-2 gap-2">
                <div><span class="text-muted">Hostname:</span> {{ connectionInfo.hostname }}</div>
                <div><span class="text-muted">OS:</span> {{ connectionInfo.os }}</div>
                <div><span class="text-muted">Uptime:</span> {{ connectionInfo.uptime }}</div>
              </div>
            </div>

            <div class="flex justify-end pt-4 border-t border-[rgb(var(--color-border))]">
              <button @click="step = 2" :disabled="!canProceedToStep2" class="btn btn-primary">
                Next: Extraction
                <span class="material-symbols-rounded">arrow_forward</span>
              </button>
            </div>
          </div>
        </div>

        <!-- Step 2: Extraction -->
        <div v-show="step === 2" class="card">
          <div class="card-header">
            <h2 class="font-semibold">Step 2: Extract Configuration</h2>
          </div>
          <div class="card-body space-y-6">

            <!-- Snapshot Flow (local server) -->
            <template v-if="connectionForm.is_local && useSnapshotFlow">
              <div class="p-4 rounded-xl bg-primary-500/10 border border-primary-500/30 mb-4">
                <div class="flex items-center gap-3">
                  <span class="material-symbols-rounded text-primary-500 text-2xl">photo_camera</span>
                  <div>
                    <p class="font-medium text-primary-600 dark:text-primary-400">Snapshot Mode</p>
                    <p class="text-sm text-muted">Reads all configs from this server via the agent and stores them as JSON. Fast, reliable, server-side.</p>
                  </div>
                </div>
              </div>

              <!-- Extraction Mode Selection -->
              <div>
                <label class="block text-sm font-medium mb-3">Extraction Mode</label>
                <div class="grid grid-cols-2 gap-3">
                  <button
                    type="button"
                    @click="extractionMode = 'full_clone'"
                    :class="[
                      'p-4 rounded-xl border-2 text-left transition-all',
                      extractionMode === 'full_clone' 
                        ? 'border-primary-500 bg-primary-500/10' 
                        : 'border-[rgb(var(--color-border))] hover:border-[rgb(var(--color-border-strong))]'
                    ]"
                  >
                    <div class="flex items-start gap-3">
                      <span class="material-symbols-rounded text-2xl mt-0.5" :class="extractionMode === 'full_clone' ? 'text-primary-500' : 'text-muted'">backup</span>
                      <div>
                        <p class="font-medium" :class="extractionMode === 'full_clone' ? 'text-primary-600 dark:text-primary-400' : ''">Full Clone</p>
                        <p class="text-xs text-muted mt-1">Complete snapshot including SSL, DKIM, all domain configs.</p>
                      </div>
                    </div>
                  </button>
                  <button
                    type="button"
                    @click="extractionMode = 'base_config'"
                    :class="[
                      'p-4 rounded-xl border-2 text-left transition-all',
                      extractionMode === 'base_config' 
                        ? 'border-primary-500 bg-primary-500/10' 
                        : 'border-[rgb(var(--color-border))] hover:border-[rgb(var(--color-border-strong))]'
                    ]"
                  >
                    <div class="flex items-start gap-3">
                      <span class="material-symbols-rounded text-2xl mt-0.5" :class="extractionMode === 'base_config' ? 'text-primary-500' : 'text-muted'">settings_suggest</span>
                      <div>
                        <p class="font-medium" :class="extractionMode === 'base_config' ? 'text-primary-600 dark:text-primary-400' : ''">Base Config</p>
                        <p class="text-xs text-muted mt-1">Generic server settings only, for new server setup.</p>
                      </div>
                    </div>
                  </button>
                </div>
              </div>

              <!-- Take Snapshot Button -->
              <div class="flex items-center gap-4">
                <button @click="takeNewSnapshot" :disabled="takingSnapshot" class="btn btn-primary">
                  <span v-if="takingSnapshot" class="spinner w-5 h-5"></span>
                  <span v-else class="material-symbols-rounded">photo_camera</span>
                  {{ takingSnapshot ? 'Taking Snapshot...' : 'Take New Snapshot' }}
                </button>
              </div>

              <!-- Existing Snapshots -->
              <div v-if="availableSnapshots.length > 0">
                <label class="block text-sm font-medium mb-3">Or select an existing snapshot:</label>
                <div class="space-y-2 max-h-48 overflow-y-auto">
                  <div
                    v-for="snap in availableSnapshots"
                    :key="snap.id"
                    :class="[
                      'w-full flex items-center gap-2 p-3 rounded-xl border-2 transition-all',
                      selectedSnapshotId === snap.id
                        ? 'border-primary-500 bg-primary-500/10'
                        : 'border-[rgb(var(--color-border))] hover:border-[rgb(var(--color-border-strong))]'
                    ]"
                  >
                    <button
                      @click="loadSnapshot(snap.id)"
                      class="flex items-center gap-3 flex-1 min-w-0 text-left"
                    >
                      <span class="material-symbols-rounded" :class="selectedSnapshotId === snap.id ? 'text-primary-500' : 'text-muted'">description</span>
                      <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium truncate">{{ snap.label || snap.id }}</p>
                        <p class="text-xs text-muted">
                          {{ new Date(snap.timestamp).toLocaleString() }}
                          <template v-if="snap.categories_count"> | {{ snap.categories_count }} categories</template>
                        </p>
                      </div>
                      <span v-if="selectedSnapshotId === snap.id" class="material-symbols-rounded text-primary-500">check_circle</span>
                    </button>
                    <button
                      @click.stop="deleteSnapshot(snap)"
                      :disabled="deletingSnapshot === snap.id"
                      class="p-1 rounded-lg text-surface-400 hover:text-red-500 hover:bg-red-500/10 transition-colors shrink-0"
                      title="Delete snapshot"
                    >
                      <span v-if="deletingSnapshot === snap.id" class="spinner-sm"></span>
                      <span v-else class="material-symbols-rounded text-base">delete</span>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Selected Snapshot Summary -->
              <div v-if="selectedSnapshot && selectedSnapshot.summary" class="bg-[rgb(var(--color-surface-hover))] rounded-lg p-4">
                <h3 class="font-medium mb-3 flex items-center gap-2">
                  <span class="material-symbols-rounded text-primary-400">summarize</span>
                  Snapshot Summary
                  <span class="badge badge-success">LOADED</span>
                </h3>
                <div class="grid grid-cols-3 gap-4 mb-3">
                  <div class="text-center">
                    <p class="text-2xl font-bold text-primary-400">{{ selectedSnapshot.summary.total_files }}</p>
                    <p class="text-xs text-muted">Files</p>
                  </div>
                  <div class="text-center">
                    <p class="text-2xl font-bold text-green-400">{{ selectedSnapshot.summary.total_categories }}</p>
                    <p class="text-xs text-muted">Categories</p>
                  </div>
                  <div class="text-center">
                    <p class="text-2xl font-bold text-amber-400">{{ formatBytes(selectedSnapshot.summary.total_size) }}</p>
                    <p class="text-xs text-muted">Total Size</p>
                  </div>
                </div>
                <!-- Installed services -->
                <div v-if="selectedSnapshot.installed_services?.length" class="flex flex-wrap gap-1 mt-2">
                  <span v-for="svc in selectedSnapshot.installed_services" :key="svc.category" class="badge badge-neutral text-xs">
                    {{ svc.name }}
                  </span>
                </div>
              </div>

              <!-- Preview Templates (dynamically generated from snapshot) -->
              <div v-if="selectedSnapshotId" class="pt-3 border-t border-[rgb(var(--color-border))]">
                <button @click="previewTemplatesFromSnapshot" :disabled="previewingTemplates" class="btn btn-ghost w-full justify-start gap-2">
                  <span v-if="previewingTemplates" class="spinner w-4 h-4"></span>
                  <span v-else class="material-symbols-rounded">preview</span>
                  {{ previewingTemplates ? 'Generating Preview...' : 'Preview Generated Templates' }}
                </button>

                <!-- Template Preview Results -->
                <div v-if="templatePreview" class="mt-4 space-y-3">
                  <div class="flex items-center justify-between">
                    <h3 class="font-medium text-sm flex items-center gap-2">
                      <span class="material-symbols-rounded text-green-400">auto_awesome</span>
                      Dynamically Generated Templates
                    </h3>
                    <div class="flex gap-2 text-xs">
                      <span class="badge badge-primary">{{ templatePreview.stats?.total_templates || 0 }} templates</span>
                      <span class="badge badge-amber">{{ templatePreview.stats?.total_variables || 0 }} variables detected</span>
                    </div>
                  </div>

                  <p class="text-xs text-muted">
                    These templates were generated by reading the actual server configs and replacing server-specific values (IPs, passwords, domains) with {{VARIABLE}} placeholders.
                  </p>

                  <!-- Category breakdown -->
                  <div v-for="(count, category) in (templatePreview.stats?.categories || {})" :key="category" class="border border-[rgb(var(--color-border))] rounded-lg overflow-hidden">
                    <button @click="togglePreviewCategory(category)" class="w-full flex items-center justify-between p-3 hover:bg-[rgb(var(--color-surface-hover))] transition-colors">
                      <span class="font-medium text-sm flex items-center gap-2">
                        <span class="material-symbols-rounded text-primary-400 text-sm">folder</span>
                        {{ category }}
                        <span class="badge badge-neutral text-xs">{{ count }} files</span>
                      </span>
                      <span class="material-symbols-rounded text-muted text-sm transition-transform" :class="{ 'rotate-180': previewExpanded[category] }">expand_more</span>
                    </button>
                    <div v-if="previewExpanded[category]" class="border-t border-[rgb(var(--color-border))] p-3 space-y-2">
                      <div
                        v-for="tpl in (templatePreview.templates || []).filter(t => t.category === category)"
                        :key="tpl.target_path"
                        class="text-xs"
                      >
                        <div class="flex items-center gap-2 mb-1">
                          <span class="material-symbols-rounded text-muted text-xs">description</span>
                          <code class="text-primary-400">{{ tpl.target_path }}</code>
                          <span v-if="tpl.variables_used?.length" class="badge badge-amber text-[10px]">{{ tpl.variables_used.length }} vars</span>
                        </div>
                        <div v-if="tpl.variables_used?.length" class="flex flex-wrap gap-1 ml-6">
                          <span v-for="v in tpl.variables_used" :key="v" class="px-1.5 py-0.5 bg-amber-500/10 text-amber-400 rounded text-[10px] font-mono" v-text="wrapVar(v)"></span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Detected Variables Summary -->
                  <div v-if="templatePreview.variable_map && Object.keys(templatePreview.variable_map).length" class="bg-amber-500/5 border border-amber-500/20 rounded-lg p-3">
                    <h4 class="text-xs font-medium text-amber-400 mb-2 flex items-center gap-1">
                      <span class="material-symbols-rounded text-sm">key</span>
                      Detected Variables (auto-replaced)
                    </h4>
                    <div class="grid grid-cols-2 gap-1 text-xs">
                      <div v-for="(value, name) in templatePreview.variable_map" :key="name" class="flex items-center gap-2 truncate">
                        <code class="text-amber-400 font-mono">{{ name }}</code>
                        <span class="text-muted">=</span>
                        <code class="text-muted truncate" :title="value">{{ name.includes('PASS') || name.includes('SECRET') || name.includes('KEY') ? '****' : value }}</code>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Toggle to legacy extraction flow -->
              <div class="pt-3 border-t border-[rgb(var(--color-border))]">
                <button @click="useSnapshotFlow = false" class="text-xs text-muted hover:text-default transition-colors flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm">swap_horiz</span>
                  Switch to legacy chunked extraction
                </button>
              </div>
            </template>

            <!-- Legacy Extraction Flow (remote servers, or toggled) -->
            <template v-else>
              <!-- Switch back to snapshot -->
              <div v-if="connectionForm.is_local" class="mb-4">
                <button @click="useSnapshotFlow = true" class="text-xs text-primary-500 hover:text-primary-400 transition-colors flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm">swap_horiz</span>
                  Switch to snapshot mode (recommended for local server)
                </button>
              </div>

              <!-- Extraction Mode Selection -->
              <div>
                <label class="block text-sm font-medium mb-3">Extraction Mode</label>
                <div class="grid grid-cols-2 gap-3">
                  <button
                    type="button"
                    @click="extractionMode = 'full_clone'"
                    :class="[
                      'p-4 rounded-xl border-2 text-left transition-all',
                      extractionMode === 'full_clone' 
                        ? 'border-primary-500 bg-primary-500/10' 
                        : 'border-[rgb(var(--color-border))] hover:border-[rgb(var(--color-border-strong))]'
                    ]"
                  >
                    <div class="flex items-start gap-3">
                      <span class="material-symbols-rounded text-2xl mt-0.5" :class="extractionMode === 'full_clone' ? 'text-primary-500' : 'text-muted'">backup</span>
                      <div>
                        <p class="font-medium" :class="extractionMode === 'full_clone' ? 'text-primary-600 dark:text-primary-400' : ''">Full Clone</p>
                        <p class="text-xs text-muted mt-1">Complete server snapshot including all SSL certificates, DKIM keys, and domain-specific configs. Best for server migration or backup.</p>
                      </div>
                    </div>
                  </button>
                  <button
                    type="button"
                    @click="extractionMode = 'base_config'"
                    :class="[
                      'p-4 rounded-xl border-2 text-left transition-all',
                      extractionMode === 'base_config' 
                        ? 'border-primary-500 bg-primary-500/10' 
                        : 'border-[rgb(var(--color-border))] hover:border-[rgb(var(--color-border-strong))]'
                    ]"
                  >
                    <div class="flex items-start gap-3">
                      <span class="material-symbols-rounded text-2xl mt-0.5" :class="extractionMode === 'base_config' ? 'text-primary-500' : 'text-muted'">settings_suggest</span>
                      <div>
                        <p class="font-medium" :class="extractionMode === 'base_config' ? 'text-primary-600 dark:text-primary-400' : ''">Base Config</p>
                        <p class="text-xs text-muted mt-1">Generic server settings only. Excludes domain-specific SSL/DKIM. Best for setting up new servers.</p>
                      </div>
                    </div>
                  </button>
                </div>
              </div>

              <!-- Base Config Options (only shown in base_config mode) -->
              <div v-if="extractionMode === 'base_config'" class="bg-surface-700/50 rounded-xl p-4 space-y-4">
                <div>
                  <label class="block text-sm font-medium mb-3">Core Applications to Include</label>
                  <p class="text-xs text-muted mb-3">These are your custom applications. Their vhost configs will be included.</p>
                  <div class="flex flex-wrap gap-3">
                    <label 
                      v-for="app in [
                        { key: 'panel', name: 'VPS Admin Panel', icon: 'dashboard' },
                        { key: 'emailapp', name: 'MailFlow Email', icon: 'email' },
                        { key: 'fleetmanager', name: 'Fleet Manager', icon: 'dns' }
                      ]" 
                      :key="app.key"
                      class="flex items-center gap-2 p-2 rounded-lg border border-[rgb(var(--color-border))] hover:border-[rgb(var(--color-border-strong))] cursor-pointer transition-colors"
                      :class="includeCoreApps.includes(app.key) ? 'bg-primary-500/10 border-primary-500' : ''"
                    >
                      <input 
                        type="checkbox" 
                        :value="app.key" 
                        v-model="includeCoreApps"
                        class="sr-only"
                      />
                      <span 
                        class="w-5 h-5 rounded border flex items-center justify-center"
                        :class="includeCoreApps.includes(app.key) ? 'bg-primary-500 border-primary-500' : 'border-[rgb(var(--color-border))]'"
                      >
                        <span v-if="includeCoreApps.includes(app.key)" class="material-symbols-rounded text-white text-sm">check</span>
                      </span>
                      <span class="material-symbols-rounded text-muted">{{ app.icon }}</span>
                      <span class="text-sm">{{ app.name }}</span>
                    </label>
                  </div>
                </div>

                <div class="pt-3 border-t border-[rgb(var(--color-border))]">
                  <p class="text-xs text-amber-400 flex items-center gap-2">
                    <span class="material-symbols-rounded text-sm">info</span>
                    Domain-specific configs (Let's Encrypt certs, OpenDKIM keys, OpenDMARC, other vhosts) will be excluded.
                  </p>
                </div>
              </div>

              <!-- Extraction Buttons -->
              <div>
                <p class="text-surface-400 text-sm mb-4">
                  First run a <strong>Dry Run</strong> to preview what will be collected.
                  Then run the <strong>Actual Extraction</strong> to capture the configs.
                </p>

                <div class="flex items-center gap-4">
                  <button @click="runDryRun" :disabled="extracting" class="btn btn-secondary">
                    <span v-if="extracting" class="spinner w-5 h-5"></span>
                    <span v-else class="material-symbols-rounded">preview</span>
                    {{ extracting ? 'Running...' : 'Dry Run (Preview)' }}
                  </button>

                  <button 
                    @click="runExtraction" 
                    :disabled="extracting" 
                    class="btn btn-primary"
                  >
                    <span v-if="extracting" class="spinner w-5 h-5"></span>
                    <span v-else class="material-symbols-rounded">download</span>
                    {{ extracting ? 'Extracting...' : 'Run Actual Extraction' }}
                  </button>
                </div>
              </div>
            </template>

            <!-- Extraction Summary -->
            <div v-if="extractionSummary" class="bg-surface-700 rounded-lg p-4">
              <h3 class="font-medium mb-3 flex items-center gap-2">
                <span class="material-symbols-rounded text-primary-400">summarize</span>
                Extraction Summary
                <span v-if="extractionResult?.dry_run" class="badge badge-warning">DRY RUN</span>
                <span v-else class="badge badge-success">COMPLETE</span>
              </h3>
              
              <div class="grid grid-cols-4 gap-4 mb-4">
                <div class="text-center">
                  <p class="text-2xl font-bold text-primary-400">{{ extractionSummary.total_files }}</p>
                  <p class="text-xs text-surface-400">Files</p>
                </div>
                <div class="text-center">
                  <p class="text-2xl font-bold text-green-400">{{ extractionSummary.categories_extracted }}</p>
                  <p class="text-xs text-surface-400">Categories</p>
                </div>
                <div class="text-center">
                  <p class="text-2xl font-bold text-surface-400">{{ extractionSummary.categories_skipped }}</p>
                  <p class="text-xs text-surface-400">Skipped</p>
                </div>
                <div class="text-center">
                  <p class="text-2xl font-bold" :class="extractionSummary.errors_count > 0 ? 'text-red-400' : 'text-green-400'">
                    {{ extractionSummary.errors_count }}
                  </p>
                  <p class="text-xs text-surface-400">Errors</p>
                </div>
              </div>

              <div class="space-y-2">
                <div v-for="(cat, name) in extractionSummary.categories" :key="name" 
                     class="flex items-center justify-between text-sm">
                  <span>{{ cat.name }}</span>
                  <span class="text-surface-400">{{ cat.files }} files ({{ formatSize(cat.size) }})</span>
                </div>
              </div>
            </div>

            <!-- Skipped Services -->
            <div v-if="extractionResult?.skipped && Object.keys(extractionResult.skipped).length > 0" 
                 class="bg-surface-700/50 rounded-lg p-4">
              <h4 class="text-sm font-medium text-surface-400 mb-2">Skipped (not installed):</h4>
              <div class="flex flex-wrap gap-2">
                <span v-for="(reason, service) in extractionResult.skipped" :key="service" 
                      class="badge badge-neutral">
                  {{ service }}
                </span>
              </div>
            </div>

            <!-- Extracted Files List -->
            <div v-if="allExtractedFiles.length > 0 && !extractionResult?.dry_run" class="bg-surface-700 rounded-lg overflow-hidden">
              <div class="px-4 py-3 border-b border-surface-600 flex items-center justify-between">
                <h4 class="font-medium flex items-center gap-2">
                  <span class="material-symbols-rounded text-primary-400">folder_open</span>
                  Extracted Files ({{ allExtractedFiles.length }})
                </h4>
              </div>
              <div class="max-h-64 overflow-y-auto">
                <div v-for="file in allExtractedFiles" :key="file.path"
                     @click="openFilePreview(file)"
                     class="px-4 py-2 border-b border-surface-800 hover:bg-surface-600/50 cursor-pointer flex items-center justify-between text-sm">
                  <div class="flex items-center gap-3 min-w-0">
                    <span class="material-symbols-rounded text-surface-400 text-lg">description</span>
                    <div class="min-w-0">
                      <p class="font-medium truncate">{{ file.filename }}</p>
                      <p class="text-xs text-surface-400 truncate">{{ file.path }}</p>
                    </div>
                  </div>
                  <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="badge badge-neutral text-xs">{{ file.category }}</span>
                    <span class="text-surface-400 text-xs">{{ formatSize(file.size) }}</span>
                    <span class="material-symbols-rounded text-surface-400 text-lg">visibility</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="flex justify-between pt-4 border-t border-[rgb(var(--color-border))]">
              <button @click="step = 1" class="btn btn-ghost">
                <span class="material-symbols-rounded">arrow_back</span>
                Back
              </button>
              <button
                @click="step = 3"
                :disabled="!(canProceedToStep3 || (useSnapshotFlow && selectedSnapshot))"
                class="btn btn-primary"
              >
                Next: Save Blueprint
                <span class="material-symbols-rounded">arrow_forward</span>
              </button>
            </div>
          </div>
        </div>

        <!-- Step 3: Save Blueprint -->
        <div v-show="step === 3" class="card">
          <div class="card-header">
            <h2 class="font-semibold">Step 3: Save Blueprint</h2>
          </div>
          <div class="card-body space-y-4">
            <div>
              <label class="block text-sm font-medium mb-2">Blueprint Name *</label>
              <input v-model="blueprintForm.name" type="text" class="input w-full" placeholder="e.g., Production Server v1.0" />
            </div>

            <div>
              <label class="block text-sm font-medium mb-2">Description</label>
              <textarea v-model="blueprintForm.description" class="input w-full" rows="3" 
                        placeholder="Optional description of this blueprint..."></textarea>
            </div>

            <!-- Detected Variables Section -->
            <div class="space-y-4">
              <div class="flex items-center justify-between">
                <h4 class="font-medium flex items-center gap-2">
                  <span class="material-symbols-rounded text-amber-400">tune</span>
                  Template Variables
                </h4>
                <span class="text-xs text-surface-400">
                  {{ Object.keys(detectedVariables.detected || {}).filter(k => detectedVariables.detected[k]).length }} detected
                </span>
              </div>
              
              <p class="text-sm text-surface-400">
                These values will be replaced with <code class="px-1 bg-surface-700 rounded" v-text="'&#123;&#123;VARIABLE&#125;&#125;'"></code> placeholders in templates.
                Review and edit as needed.
              </p>

              <!-- Variable Categories -->
              <div class="space-y-3">
                <div v-for="category in variableCategories" :key="category.key" class="border border-[rgb(var(--color-border))] rounded-lg overflow-hidden">
                  <!-- Category Header -->
                  <button 
                    @click="toggleCategory(category.key)"
                    class="w-full flex items-center justify-between px-4 py-3 bg-surface-700/50 hover:bg-surface-700 transition-colors"
                  >
                    <div class="flex items-center gap-3">
                      <span class="material-symbols-rounded text-primary-400">{{ category.icon }}</span>
                      <span class="font-medium">{{ category.label }}</span>
                      <span class="text-xs text-surface-400">
                        ({{ getVariablesByCategory(category.key).filter(v => hasDetectedValue(v.name)).length }}/{{ getVariablesByCategory(category.key).length }})
                      </span>
                    </div>
                    <span class="material-symbols-rounded text-surface-400">
                      {{ expandedCategories.includes(category.key) ? 'expand_less' : 'expand_more' }}
                    </span>
                  </button>

                  <!-- Category Variables -->
                  <div v-show="expandedCategories.includes(category.key)" class="divide-y divide-[rgb(var(--color-border))]">
                    <div 
                      v-for="varDef in getVariablesByCategory(category.key)" 
                      :key="varDef.name"
                      class="px-4 py-3 hover:bg-surface-700/30"
                    >
                      <div class="flex items-start justify-between gap-4">
                        <div class="flex-1 min-w-0">
                          <div class="flex items-center gap-2 mb-1">
                            <code class="text-sm font-mono text-primary-400" v-text="formatVarPlaceholder(varDef.name)"></code>
                            <span v-if="varDef.required" class="text-red-400 text-xs">*required</span>
                            <span v-if="varDef.generate" class="badge badge-neutral text-xs">auto-generate</span>
                            <span v-if="hasDetectedValue(varDef.name)" class="badge badge-success text-xs">detected</span>
                          </div>
                          <p class="text-sm text-surface-400">{{ varDef.label }}</p>
                          
                          <!-- Show where variable was found -->
                          <div v-if="detectedVariables.found_in?.[varDef.name]?.length" class="mt-1 flex flex-wrap gap-1">
                            <span 
                              v-for="(loc, idx) in detectedVariables.found_in[varDef.name].slice(0, 3)" 
                              :key="idx"
                              class="text-xs text-surface-500"
                            >
                              {{ loc.file.split('/').pop() }}{{ idx < Math.min(detectedVariables.found_in[varDef.name].length, 3) - 1 ? ',' : '' }}
                            </span>
                            <span v-if="detectedVariables.found_in[varDef.name].length > 3" class="text-xs text-surface-500">
                              +{{ detectedVariables.found_in[varDef.name].length - 3 }} more
                            </span>
                          </div>
                        </div>

                        <div class="flex-shrink-0 w-64">
                          <!-- Password type -->
                          <div v-if="varDef.type === 'password'" class="flex items-center gap-2">
                            <div class="relative flex-1">
                              <input 
                                :type="editingVariable === varDef.name ? 'text' : 'password'"
                                :value="getVariableDisplayValue(varDef)"
                                @input="updateVariableValue(varDef.name, $event.target.value)"
                                @focus="editingVariable = varDef.name"
                                @blur="editingVariable = null"
                                class="input w-full pr-20 text-sm"
                                :placeholder="varDef.placeholder || 'Enter password...'"
                              />
                              <div class="absolute right-1 top-1/2 -translate-y-1/2 flex items-center gap-1">
                                <button 
                                  @click="editingVariable = editingVariable === varDef.name ? null : varDef.name"
                                  class="p-1 hover:bg-surface-600 rounded"
                                  :title="editingVariable === varDef.name ? 'Hide' : 'Show'"
                                >
                                  <span class="material-symbols-rounded text-sm text-surface-400">
                                    {{ editingVariable === varDef.name ? 'visibility_off' : 'visibility' }}
                                  </span>
                                </button>
                                <button 
                                  v-if="varDef.generate"
                                  @click="generatePassword(varDef.name)"
                                  class="p-1 hover:bg-surface-600 rounded"
                                  title="Generate random password"
                                >
                                  <span class="material-symbols-rounded text-sm text-surface-400">casino</span>
                                </button>
                              </div>
                            </div>
                          </div>
                          
                          <!-- Text/Email type -->
                          <input 
                            v-else
                            type="text"
                            :value="getVariableDisplayValue(varDef)"
                            @input="updateVariableValue(varDef.name, $event.target.value)"
                            class="input w-full text-sm"
                            :placeholder="varDef.placeholder || varDef.default || 'Enter value...'"
                          />
                        </div>
                      </div>
                    </div>
                    
                    <div v-if="getVariablesByCategory(category.key).length === 0" class="px-4 py-3 text-sm text-surface-500 italic">
                      No variables in this category
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Summary of what will be saved -->
            <div v-if="extractionSummary" class="bg-green-500/10 border border-green-500/30 rounded-lg p-4">
              <h4 class="font-medium text-green-400 mb-2 flex items-center gap-2">
                <span class="material-symbols-rounded">inventory_2</span>
                Blueprint Contents
              </h4>
              <p class="text-sm text-surface-300">
                This blueprint will contain <strong>{{ extractionSummary.total_files }}</strong> configuration templates
                from <strong>{{ extractionSummary.categories_extracted }}</strong> categories,
                totaling <strong>{{ extractionSummary.total_size_human }}</strong>.
              </p>
            </div>

            <div class="flex justify-between pt-4 border-t border-surface-700">
              <button @click="step = 2" class="btn btn-ghost">
                <span class="material-symbols-rounded">arrow_back</span>
                Back
              </button>
              <button
                @click="useSnapshotFlow && selectedSnapshotId ? saveBlueprintFromSnapshot() : saveBlueprint()"
                :disabled="saving"
                class="btn btn-primary"
              >
                <span v-if="saving" class="spinner w-5 h-5"></span>
                <span v-else class="material-symbols-rounded">save</span>
                {{ saving ? 'Saving...' : 'Create Blueprint' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Debug Panel (Right Sidebar) -->
      <div class="lg:col-span-1">
        <div class="card sticky top-6">
          <div class="card-header flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-400">terminal</span>
              <h3 class="font-semibold">Debug Console</h3>
            </div>
            <div class="flex items-center gap-2">
              <button @click="clearDebugLogs" class="btn btn-ghost btn-sm" title="Clear logs">
                <span class="material-symbols-rounded text-lg">delete</span>
              </button>
              <button @click="debugExpanded = !debugExpanded" class="btn btn-ghost btn-sm">
                <span class="material-symbols-rounded text-lg">
                  {{ debugExpanded ? 'expand_less' : 'expand_more' }}
                </span>
              </button>
            </div>
          </div>
          
          <div v-show="debugExpanded" class="card-body p-0">
            <!-- Controls -->
            <div class="px-4 py-2 border-b border-surface-700 flex items-center justify-between">
              <label class="flex items-center gap-2 text-sm text-surface-400 cursor-pointer">
                <button 
                  type="button"
                  @click="autoScroll = !autoScroll"
                  :class="['toggle', { active: autoScroll }]"
                >
                  <span class="toggle-dot"></span>
                </button>
                Auto-scroll
              </label>
              <span class="text-xs text-surface-500">{{ debugLogs.length }} entries</span>
            </div>

            <!-- Logs -->
            <div id="debug-container" class="h-[500px] overflow-y-auto font-mono text-xs">
              <div v-if="debugLogs.length === 0" class="p-4 text-center text-surface-500">
                Debug output will appear here
              </div>
              
              <div v-for="log in debugLogs" :key="log.id" 
                   class="px-3 py-2 border-b border-surface-800 hover:bg-surface-700/30">
                <div class="flex items-start gap-2">
                  <span :class="['material-symbols-rounded text-sm', getLogLevelClass(log.level)]">
                    {{ getLogLevelIcon(log.level) }}
                  </span>
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                      <span class="text-surface-500">{{ log.timestamp }}</span>
                      <span :class="getLogLevelClass(log.level)">{{ log.message }}</span>
                    </div>
                    
                    <!-- Expandable data -->
                    <div v-if="log.data" class="mt-1">
                      <button @click="log.expanded = !log.expanded" 
                              class="text-surface-400 hover:text-white flex items-center gap-1">
                        <span class="material-symbols-rounded text-sm">
                          {{ log.expanded ? 'expand_less' : 'expand_more' }}
                        </span>
                        <span>{{ log.expanded ? 'Hide' : 'Show' }} details</span>
                      </button>
                      <pre v-if="log.expanded" 
                           class="mt-2 p-2 bg-surface-900 rounded text-surface-300 overflow-x-auto">{{ JSON.stringify(log.data, null, 2) }}</pre>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- File Preview Modal -->
    <div v-if="showFilePreview" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/70" @click="closeFilePreview"></div>
      <div class="relative bg-surface-800 rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="flex items-center justify-between px-6 py-4 border-b border-surface-700">
          <div>
            <h3 class="font-semibold text-lg">{{ previewFile?.filename }}</h3>
            <p class="text-sm text-surface-400">{{ previewFile?.path }}</p>
          </div>
          <button @click="closeFilePreview" class="btn btn-ghost btn-sm">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <!-- File Info -->
        <div class="px-6 py-3 border-b border-surface-700 flex items-center gap-6 text-sm">
          <span class="text-surface-400">
            <span class="material-symbols-rounded text-sm align-middle mr-1">straighten</span>
            {{ formatSize(previewFile?.size || 0) }}
          </span>
          <span class="text-surface-400">
            <span class="material-symbols-rounded text-sm align-middle mr-1">lock</span>
            {{ previewFile?.permissions || '0644' }}
          </span>
          <span class="text-surface-400">
            <span class="material-symbols-rounded text-sm align-middle mr-1">person</span>
            {{ previewFile?.owner || 'root' }}:{{ previewFile?.group || 'root' }}
          </span>
        </div>

        <!-- File Content -->
        <div class="flex-1 overflow-auto p-4">
          <pre v-if="previewFile?.dry_run" class="text-surface-400 italic text-center py-8">
[Dry Run - Content not extracted]
          </pre>
          <pre v-else class="text-sm font-mono bg-surface-900 rounded-lg p-4 overflow-x-auto whitespace-pre-wrap">{{ previewFile?.content }}</pre>
        </div>

        <!-- Modal Footer -->
        <div class="px-6 py-4 border-t border-surface-700 flex justify-end gap-3">
          <button @click="closeFilePreview" class="btn btn-ghost">Close</button>
        </div>
      </div>
    </div>
  </div>
</template>

