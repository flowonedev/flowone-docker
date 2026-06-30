<template>
  <div
    v-if="showPanel"
    ref="panelRef"
    class="zip-debug-panel"
    :style="{ left: position.x + 'px', top: position.y + 'px' }"
  >
    <div class="zip-debug-header" @mousedown="startDrag">
      <div class="zip-debug-title">
        <span class="material-icons">bug_report</span>
        ZIP Debug Panel
      </div>
      <div class="zip-debug-actions">
        <button @click="clearDebug" class="icon-btn" title="Clear">
          <span class="material-icons">clear</span>
        </button>
        <button @click="showPanel = false" class="icon-btn" title="Close">
          <span class="material-icons">close</span>
        </button>
      </div>
    </div>
    
    <div class="zip-debug-content">
      <div class="zip-debug-status">
        <div class="status-item">
          <span class="label">Status:</span>
          <span :class="['status-badge', statusClass]">{{ status }}</span>
        </div>
        <div class="status-item">
          <span class="label">Session ID:</span>
          <span class="value">{{ sessionId || 'None' }}</span>
        </div>
        <div class="status-item">
          <span class="label">Files Found:</span>
          <span class="value">{{ filesFound }}</span>
        </div>
        <div class="status-item">
          <span class="label">Files Added:</span>
          <span class="value">{{ filesAdded }}</span>
        </div>
        <div class="status-item">
          <span class="label">Errors:</span>
          <span class="value error">{{ errors.length }}</span>
        </div>
      </div>
      
      <div class="zip-debug-tabs">
        <button
          v-for="tab in tabs"
          :key="tab"
          @click="activeTab = tab"
          :class="['tab-btn', { active: activeTab === tab }]"
        >
          {{ tab }}
        </button>
      </div>
      
      <div class="zip-debug-body">
        <!-- Steps Tab -->
        <div v-if="activeTab === 'Steps'" class="tab-content">
          <div class="steps-list">
            <div
              v-for="(step, index) in steps"
              :key="index"
              :class="['step-item', step.step.toLowerCase().replace(/_/g, '-')]"
            >
              <div class="step-header">
                <span class="step-time">{{ step.time }}</span>
                <span class="step-name">{{ step.step }}</span>
              </div>
              <div v-if="step.data && Object.keys(step.data).length > 0" class="step-data">
                <pre>{{ JSON.stringify(step.data, null, 2) }}</pre>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Files Tab -->
        <div v-if="activeTab === 'Files'" class="tab-content">
          <div class="files-list">
            <div
              v-for="(file, index) in files"
              :key="index"
              :class="['file-item', { error: !file.exists || !file.readable }]"
            >
              <div class="file-header">
                <span class="file-index">#{{ index + 1 }}</span>
                <span class="file-name">{{ file.name || file.filename }}</span>
                <span class="file-size">{{ formatSize(file.size || file.actual_size || 0) }}</span>
              </div>
              <div class="file-details">
                <div class="file-detail">
                  <span class="detail-label">Path:</span>
                  <span class="detail-value">{{ file.path }}</span>
                </div>
                <div class="file-detail">
                  <span class="detail-label">Exists:</span>
                  <span :class="['detail-value', file.exists ? 'success' : 'error']">
                    {{ file.exists ? 'Yes' : 'No' }}
                  </span>
                </div>
                <div class="file-detail">
                  <span class="detail-label">Readable:</span>
                  <span :class="['detail-value', file.readable ? 'success' : 'error']">
                    {{ file.readable ? 'Yes' : 'No' }}
                  </span>
                </div>
                <div v-if="file.relative_path" class="file-detail">
                  <span class="detail-label">Zip Entry:</span>
                  <span class="detail-value">{{ file.relative_path }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Errors Tab -->
        <div v-if="activeTab === 'Errors'" class="tab-content">
          <div v-if="errors.length === 0" class="no-errors">
            No errors found
          </div>
          <div v-else class="errors-list">
            <div
              v-for="(error, index) in errors"
              :key="index"
              class="error-item"
            >
              <div class="error-time">{{ error.time }}</div>
              <div class="error-message">{{ error.message }}</div>
              <div v-if="error.data" class="error-data">
                <pre>{{ JSON.stringify(error.data, null, 2) }}</pre>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <button
    v-if="!showPanel && debugEnabled && bugReportEnabled"
    @click="showPanel = true"
    class="zip-debug-toggle"
    title="Show ZIP Debug Panel"
  >
    <span class="material-symbols-rounded">bug_report</span>
  </button>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { isDebugEnabled } from '@/utils/debug'
import { getToken } from '@/services/tokenStorage'

const showPanel = ref(false)
const debugEnabled = ref(isDebugEnabled())
// Also respect the "Bug Report Button" setting so disabling it hides this
// floating bug button too (users treat both as "the bug button").
const bugReportEnabled = ref(localStorage.getItem('bug_report_enabled') !== 'false')
const panelRef = ref(null)
const position = ref({ x: 50, y: 50 })
const isDragging = ref(false)
const dragOffset = ref({ x: 0, y: 0 })
const activeTab = ref('Steps')
const tabs = ['Steps', 'Files', 'Errors']
const sessionId = ref('')
const debugData = ref({
  status: 'waiting',
  steps: [],
  files: [],
  errors: []
})

const status = computed(() => debugData.value.status || 'waiting')
const steps = computed(() => debugData.value.steps || [])
const files = computed(() => {
  const fileSteps = steps.value.filter(s => s.step === 'FILES_COLLECTED')
  if (fileSteps.length > 0 && fileSteps[fileSteps.length - 1].data?.files) {
    return fileSteps[fileSteps.length - 1].data.files
  }
  return []
})
const errors = computed(() => {
  return steps.value
    .filter(s => s.step.includes('FAILED') || s.step.includes('ERROR') || s.step.includes('NOT_FOUND'))
    .map(s => ({
      time: s.time,
      message: s.step,
      data: s.data
    }))
})
const filesFound = computed(() => files.value.length)
const filesAdded = computed(() => {
  const addedStep = steps.value.find(s => s.step === 'ZIP_CREATED')
  return addedStep?.data?.addedCount || 0
})
const statusClass = computed(() => {
  if (status.value === 'COMPLETE') return 'success'
  if (status.value === 'waiting') return 'waiting'
  if (errors.value.length > 0) return 'error'
  return 'processing'
})

let pollInterval = null
let debugCheckInterval = null

const startDrag = (e) => {
  if (e.target.closest('.zip-debug-actions')) return
  isDragging.value = true
  const rect = panelRef.value.getBoundingClientRect()
  dragOffset.value = {
    x: e.clientX - rect.left,
    y: e.clientY - rect.top
  }
  document.addEventListener('mousemove', onDrag)
  document.addEventListener('mouseup', stopDrag)
}

const onDrag = (e) => {
  if (!isDragging.value) return
  position.value = {
    x: e.clientX - dragOffset.value.x,
    y: e.clientY - dragOffset.value.y
  }
}

const stopDrag = () => {
  isDragging.value = false
  document.removeEventListener('mousemove', onDrag)
  document.removeEventListener('mouseup', stopDrag)
}

const fetchDebug = async () => {
  if (!sessionId.value) {
    isDebugEnabled() && console.log('ZipDebugPanel: No session ID, skipping fetch')
    return
  }
  
  try {
    const token = getToken('webmail_token')
    // Use same API URL as download request for consistency
    const apiUrl = import.meta.env.VITE_API_URL || '/api'
    const url = `${apiUrl}/drive/zip-debug?session_id=${encodeURIComponent(sessionId.value)}`
    isDebugEnabled() && console.log('ZipDebugPanel: Fetching debug from:', url)
    
    const response = await fetch(url, {
      headers: { 'Authorization': `Bearer ${token}` }
    })
    
    if (response.ok) {
      const data = await response.json()
      isDebugEnabled() && console.log('ZipDebugPanel: Received debug data:', data)
      if (data.success && data.data) {
        isDebugEnabled() && console.log('ZipDebugPanel: Steps count:', data.data.steps?.length || 0)
        isDebugEnabled() && console.log('ZipDebugPanel: Status:', data.data.status)
        debugData.value = data.data
        if (data.data.session_id) {
          sessionId.value = data.data.session_id
        }
      } else {
        isDebugEnabled() && console.warn('ZipDebugPanel: Response success but no data:', data)
      }
    } else {
      console.error('ZipDebugPanel: Response not OK:', response.status, response.statusText)
      const text = await response.text()
      console.error('ZipDebugPanel: Response body:', text)
    }
  } catch (e) {
    console.error('ZipDebugPanel: Failed to fetch debug:', e)
  }
}

const clearDebug = () => {
  debugData.value = {
    status: 'waiting',
    steps: [],
    files: [],
    errors: []
  }
  sessionId.value = ''
}

const formatSize = (bytes) => {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
}

const startPolling = () => {
  if (pollInterval) clearInterval(pollInterval)
  pollInterval = setInterval(fetchDebug, 500)
}

const stopPolling = () => {
  if (pollInterval) {
    clearInterval(pollInterval)
    pollInterval = null
  }
}

const setSessionId = (id) => {
  isDebugEnabled() && console.log('ZipDebugPanel: Setting session ID to:', id)
  sessionId.value = id
  if (showPanel.value) {
    startPolling()
    // Fetch immediately
    fetchDebug()
  }
}

const show = () => {
  // Only show if debug is enabled in settings
  if (!isDebugEnabled()) {
    isDebugEnabled() && console.log('ZipDebugPanel: Debug not enabled in settings, skipping')
    return
  }
  isDebugEnabled() && console.log('ZipDebugPanel: Showing panel, session ID:', sessionId.value)
  showPanel.value = true
  if (sessionId.value) {
    startPolling()
    // Fetch immediately
    fetchDebug()
  }
}

watch(showPanel, (newVal) => {
  if (newVal) {
    startPolling()
  } else {
    stopPolling()
  }
})

onMounted(() => {
  // Get session ID from URL or generate new one
  const urlParams = new URLSearchParams(window.location.search)
  const urlSessionId = urlParams.get('zip_debug')
  if (urlSessionId) {
    sessionId.value = urlSessionId
  }

  // Keep the toggle button in sync with the Settings -> Debug logs switch.
  // isDebugEnabled() reads localStorage, so re-check it periodically; when it
  // is turned off, hide both the toggle button and any open panel.
  debugCheckInterval = setInterval(() => {
    debugEnabled.value = isDebugEnabled()
    bugReportEnabled.value = localStorage.getItem('bug_report_enabled') !== 'false'
    if ((!debugEnabled.value || !bugReportEnabled.value) && showPanel.value) {
      showPanel.value = false
    }
  }, 1000)
})

onUnmounted(() => {
  stopPolling()
  if (debugCheckInterval) {
    clearInterval(debugCheckInterval)
    debugCheckInterval = null
  }
})

defineExpose({
  setSessionId,
  show
})
</script>

<style scoped>
.zip-debug-panel {
  position: fixed;
  width: 800px;
  max-width: 90vw;
  max-height: 90vh;
  background: rgb(var(--color-surface));
  border: 1px solid rgb(var(--color-border));
  border-radius: 12px;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
  z-index: 10000;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.zip-debug-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  background: rgb(var(--color-surface-variant));
  border-bottom: 1px solid rgb(var(--color-border));
  cursor: move;
  user-select: none;
}

.zip-debug-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 600;
  color: rgb(var(--color-on-surface));
}

.zip-debug-actions {
  display: flex;
  gap: 4px;
}

.icon-btn {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  border-radius: 6px;
  cursor: pointer;
  color: rgb(var(--color-on-surface-variant));
  transition: all 0.2s;
}

.icon-btn:hover {
  background: rgb(var(--color-surface));
  color: rgb(var(--color-primary));
}

.zip-debug-content {
  display: flex;
  flex-direction: column;
  flex: 1;
  overflow: hidden;
}

.zip-debug-status {
  padding: 12px 16px;
  background: rgb(var(--color-surface-variant));
  border-bottom: 1px solid rgb(var(--color-border));
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
}

.status-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.status-item .label {
  font-size: 12px;
  color: rgb(var(--color-on-surface-variant));
}

.status-item .value {
  font-weight: 600;
  color: rgb(var(--color-on-surface));
}

.status-item .value.error {
  color: #f44336;
}

.status-badge {
  display: inline-block;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
}

.status-badge.success {
  background: rgba(76, 175, 80, 0.2);
  color: #4caf50;
}

.status-badge.error {
  background: rgba(244, 67, 54, 0.2);
  color: #f44336;
}

.status-badge.waiting {
  background: rgba(255, 152, 0, 0.2);
  color: #ff9800;
}

.status-badge.processing {
  background: rgba(33, 150, 243, 0.2);
  color: #2196f3;
}

.zip-debug-tabs {
  display: flex;
  gap: 4px;
  padding: 8px 16px;
  border-bottom: 1px solid rgb(var(--color-border));
  background: rgb(var(--color-surface));
}

.tab-btn {
  padding: 8px 16px;
  border: none;
  background: transparent;
  color: rgb(var(--color-on-surface-variant));
  border-radius: 6px;
  cursor: pointer;
  font-weight: 500;
  transition: all 0.2s;
}

.tab-btn:hover {
  background: rgb(var(--color-surface-variant));
}

.tab-btn.active {
  background: rgb(var(--color-primary));
  color: rgb(var(--color-on-primary));
}

.zip-debug-body {
  flex: 1;
  overflow: auto;
  padding: 16px;
}

.tab-content {
  height: 100%;
}

.steps-list, .files-list, .errors-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.step-item {
  padding: 12px;
  background: rgb(var(--color-surface-variant));
  border-radius: 8px;
  border-left: 3px solid rgb(var(--color-border));
}

.step-item.file-added-to-zip {
  border-left-color: #4caf50;
}

.step-item.file-add-to-zip-failed,
.step-item.file-read-failed,
.step-item.file-not-accessible {
  border-left-color: #f44336;
}

.step-header {
  display: flex;
  gap: 12px;
  margin-bottom: 8px;
}

.step-time {
  font-size: 12px;
  color: rgb(var(--color-on-surface-variant));
  font-family: monospace;
}

.step-name {
  font-weight: 600;
  color: rgb(var(--color-on-surface));
}

.step-data {
  margin-top: 8px;
}

.step-data pre {
  margin: 0;
  padding: 8px;
  background: rgb(var(--color-surface));
  border-radius: 4px;
  font-size: 11px;
  overflow-x: auto;
}

.file-item {
  padding: 12px;
  background: rgb(var(--color-surface-variant));
  border-radius: 8px;
  border-left: 3px solid rgb(var(--color-border));
}

.file-item.error {
  border-left-color: #f44336;
}

.file-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;
}

.file-index {
  font-weight: 600;
  color: rgb(var(--color-primary));
}

.file-name {
  flex: 1;
  font-weight: 500;
  color: rgb(var(--color-on-surface));
}

.file-size {
  font-size: 12px;
  color: rgb(var(--color-on-surface-variant));
}

.file-details {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 8px;
}

.file-detail {
  display: flex;
  gap: 8px;
  font-size: 12px;
}

.detail-label {
  font-weight: 600;
  color: rgb(var(--color-on-surface-variant));
  min-width: 80px;
}

.detail-value {
  color: rgb(var(--color-on-surface));
  word-break: break-all;
}

.detail-value.success {
  color: #4caf50;
}

.detail-value.error {
  color: #f44336;
}

.error-item {
  padding: 12px;
  background: rgba(244, 67, 54, 0.1);
  border-radius: 8px;
  border-left: 3px solid #f44336;
}

.error-time {
  font-size: 12px;
  color: rgb(var(--color-on-surface-variant));
  margin-bottom: 4px;
}

.error-message {
  font-weight: 600;
  color: #f44336;
  margin-bottom: 8px;
}

.error-data {
  margin-top: 8px;
}

.error-data pre {
  margin: 0;
  padding: 8px;
  background: rgb(var(--color-surface));
  border-radius: 4px;
  font-size: 11px;
  overflow-x: auto;
}

.no-errors {
  text-align: center;
  padding: 32px;
  color: rgb(var(--color-on-surface-variant));
}

.zip-debug-toggle {
  position: fixed;
  bottom: 20px;
  right: 20px;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background: rgb(var(--color-primary));
  color: rgb(var(--color-on-primary));
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  z-index: 9999;
  transition: all 0.2s;
}

.zip-debug-toggle:hover {
  transform: scale(1.1);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4);
}
</style>

