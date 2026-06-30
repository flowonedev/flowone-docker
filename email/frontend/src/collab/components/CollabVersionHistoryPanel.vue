<template>
  <Teleport to="body">
    <Transition name="slide-panel">
      <div v-if="show" class="version-history-overlay" @click.self="$emit('close')">
        <div class="version-history-panel">
          <!-- Header -->
          <div class="panel-header">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-primary-500" style="font-size: 24px;">history</span>
              <h2 class="text-lg font-semibold text-surface-800">Version History</h2>
            </div>
            <button @click="$emit('close')" class="close-btn">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <!-- Actions -->
          <div class="panel-actions">
            <button @click="createVersion" :disabled="isCreating" class="create-version-btn">
              <span class="material-symbols-rounded" style="font-size: 18px;">{{ isCreating ? 'progress_activity' : 'add' }}</span>
              <span>{{ isCreating ? 'Saving...' : 'Save current version' }}</span>
            </button>
          </div>
          
          <!-- Version List -->
          <div class="versions-list">
            <!-- Loading state -->
            <div v-if="isLoading" class="loading-state">
              <span class="material-symbols-rounded spin">progress_activity</span>
              <span>Loading versions...</span>
            </div>
            
            <!-- Empty state -->
            <div v-else-if="versions.length === 0" class="empty-state">
              <span class="material-symbols-rounded text-surface-300" style="font-size: 48px;">history</span>
              <p class="text-surface-500">No saved versions yet</p>
              <p class="text-surface-400 text-sm">Click "Save current version" to create a snapshot</p>
            </div>
            
            <!-- Version items -->
            <div v-else class="versions-container">
              <!-- Current version indicator -->
              <div class="current-version-badge">
                <span class="material-symbols-rounded text-primary-500" style="font-size: 16px;">fiber_manual_record</span>
                <span class="text-sm font-medium text-surface-600">Current state (auto-saved)</span>
              </div>
              
              <!-- Compare mode selector -->
              <div v-if="compareMode" class="compare-mode-banner">
                <span class="material-symbols-rounded text-blue-500" style="font-size: 18px;">compare</span>
                <span class="text-sm">Select two versions to compare</span>
                <button @click="exitCompareMode" class="ml-auto text-surface-500 hover:text-surface-700 text-sm font-medium">
                  Cancel
                </button>
              </div>
              
              <div 
                v-for="(version, index) in versions" 
                :key="version.version_number"
                class="version-item"
                :class="{ 
                  'selected': selectedVersion?.version_number === version.version_number && !compareMode,
                  'compare-selected': isCompareSelected(version),
                  'confirming': confirmingRestore?.version_number === version.version_number
                }"
              >
                <div class="version-main" @click="handleVersionClick(version, index)">
                  <!-- Compare checkbox when in compare mode -->
                  <div v-if="compareMode" class="compare-checkbox" :class="{ 'checked': isCompareSelected(version) }">
                    <span v-if="isCompareSelected(version)" class="material-symbols-rounded text-white" style="font-size: 16px;">check</span>
                  </div>
                  
                  <div v-else class="version-icon">
                    <span class="material-symbols-rounded" style="font-size: 20px;">description</span>
                  </div>
                  
                  <div class="version-info">
                    <div class="version-title">
                      {{ version.version_name || `Version ${version.version_number}` }}
                    </div>
                    <div class="version-meta">
                      <span class="version-date">{{ formatDate(version.created_at) }}</span>
                      <span class="version-separator">by</span>
                      <span class="version-author">{{ formatEmail(version.created_by) }}</span>
                    </div>
                  </div>
                  <div class="version-number">#{{ version.version_number }}</div>
                </div>
                
                <!-- Restore confirmation inline -->
                <Transition name="expand">
                  <div v-if="confirmingRestore?.version_number === version.version_number" class="restore-confirm">
                    <div class="confirm-message">
                      <span class="material-symbols-rounded text-amber-500" style="font-size: 18px;">warning</span>
                      <span>Restore to this version? Current content will be saved first.</span>
                    </div>
                    <div class="confirm-actions">
                      <button @click.stop="cancelRestore" class="confirm-btn cancel">Cancel</button>
                      <button @click.stop="confirmRestore" :disabled="isRestoring" class="confirm-btn confirm">
                        {{ isRestoring ? 'Restoring...' : 'Yes, Restore' }}
                      </button>
                    </div>
                  </div>
                </Transition>
                
                <!-- Expanded actions (when not in compare mode and not confirming) -->
                <Transition name="expand">
                  <div v-if="selectedVersion?.version_number === version.version_number && !compareMode && !confirmingRestore" class="version-actions">
                    <button 
                      @click.stop="initiateRestore(version)"
                      class="action-btn restore-btn"
                    >
                      <span class="material-symbols-rounded" style="font-size: 18px;">restore</span>
                      <span>Restore this version</span>
                    </button>
                    <button 
                      v-if="versions.length > 1"
                      @click.stop="enterCompareMode(version)"
                      class="action-btn compare-btn"
                    >
                      <span class="material-symbols-rounded" style="font-size: 18px;">compare</span>
                      <span>Compare versions</span>
                    </button>
                  </div>
                </Transition>
              </div>
            </div>
          </div>
          
          <!-- Footer info -->
          <div class="panel-footer">
            <span class="material-symbols-rounded text-surface-400" style="font-size: 16px;">info</span>
            <span class="text-xs text-surface-500">
              Versions are snapshots. Restoring will replace current content.
            </span>
          </div>
        </div>
      </div>
    </Transition>
    
    <!-- Full diff comparison modal -->
    <CollabVersionCompare
      :show="showCompareModal"
      :documentUuid="documentUuid"
      :documentTitle="documentTitle"
      :documentType="documentType"
      :version1="comparedVersions[0]"
      :version2="comparedVersions[1]"
      @close="closeCompareModal"
      @restore="handleRestoreFromCompare"
    />
  </Teleport>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { collabVersionApi } from '../services/collabApiService.js'
import { useToastStore } from '@/stores/toast'
import { isDebugEnabled } from '@/utils/debug'
import CollabVersionCompare from './CollabVersionCompare.vue'

const props = defineProps({
  show: { type: Boolean, default: false },
  documentUuid: { type: String, required: true },
  documentTitle: { type: String, default: '' },
  documentType: { type: String, default: 'document' }, // 'document' or 'presentation'
})

const emit = defineEmits(['close', 'restored'])

const toast = useToastStore()

// State
const versions = ref([])
const isLoading = ref(false)
const isCreating = ref(false)
const isRestoring = ref(false)
const selectedVersion = ref(null)
const confirmingRestore = ref(null)

// Compare mode state
const compareMode = ref(false)
const comparedVersions = ref([])
const showCompareModal = ref(false)

// Load versions when panel opens
watch(() => props.show, async (isOpen) => {
  if (isOpen && props.documentUuid) {
    await loadVersions()
  } else {
    // Reset state when closing
    resetState()
  }
}, { immediate: true })

function resetState() {
  selectedVersion.value = null
  confirmingRestore.value = null
  compareMode.value = false
  comparedVersions.value = []
  showCompareModal.value = false
}

async function loadVersions() {
  isLoading.value = true
  try {
    const response = await collabVersionApi.list(props.documentUuid, { limit: 50 })
    isDebugEnabled() && console.log('[VersionHistory] API response:', response)
    // Handle different response structures
    if (response.data?.versions) {
      versions.value = response.data.versions
    } else if (response.versions) {
      versions.value = response.versions
    } else if (Array.isArray(response)) {
      versions.value = response
    } else {
      versions.value = []
    }
    isDebugEnabled() && console.log('[VersionHistory] Loaded versions:', versions.value.length)
  } catch (error) {
    console.error('Failed to load versions:', error)
    toast.error('Failed to load version history')
  } finally {
    isLoading.value = false
  }
}

async function createVersion() {
  isCreating.value = true
  try {
    // Auto-name with timestamp
    const response = await collabVersionApi.create(props.documentUuid, null)
    isDebugEnabled() && console.log('[VersionHistory] Create response:', response)
    
    // Handle different response structures
    const version = response.data?.version || response.version
    if (version) {
      toast.success('Version saved')
      await loadVersions()
    } else {
      toast.error('Failed to save version - no CRDT state')
    }
  } catch (error) {
    console.error('Failed to create version:', error)
    toast.error('Failed to save version')
  } finally {
    isCreating.value = false
  }
}

function handleVersionClick(version, index) {
  if (compareMode.value) {
    toggleCompareSelection(version)
  } else {
    selectVersion(version)
  }
}

function selectVersion(version) {
  if (selectedVersion.value?.version_number === version.version_number) {
    selectedVersion.value = null
  } else {
    selectedVersion.value = version
    confirmingRestore.value = null
  }
}

// Restore flow with inline confirmation
function initiateRestore(version) {
  confirmingRestore.value = version
}

function cancelRestore() {
  confirmingRestore.value = null
}

async function confirmRestore() {
  if (!confirmingRestore.value) return
  
  const version = confirmingRestore.value
  isRestoring.value = true
  
  try {
    // First, save current state as a version
    await collabVersionApi.create(props.documentUuid, 'Auto-save before restore')
    
    // Then restore
    await collabVersionApi.restore(props.documentUuid, version.version_number)
    
    toast.success(`Restored to Version ${version.version_number}`)
    emit('restored', version)
    emit('close')
  } catch (error) {
    console.error('Failed to restore version:', error)
    toast.error('Failed to restore version')
  } finally {
    isRestoring.value = false
    confirmingRestore.value = null
  }
}

// Compare mode functions
function enterCompareMode(version) {
  compareMode.value = true
  comparedVersions.value = [version]
  selectedVersion.value = null
}

function exitCompareMode() {
  compareMode.value = false
  comparedVersions.value = []
  showCompareModal.value = false
}

function isCompareSelected(version) {
  return comparedVersions.value.some(v => v.version_number === version.version_number)
}

function toggleCompareSelection(version) {
  const idx = comparedVersions.value.findIndex(v => v.version_number === version.version_number)
  
  if (idx !== -1) {
    // Remove from selection
    comparedVersions.value.splice(idx, 1)
  } else if (comparedVersions.value.length < 2) {
    // Add to selection
    comparedVersions.value.push(version)
    
    // If we have 2 versions, open the compare modal
    if (comparedVersions.value.length === 2) {
      showCompareModal.value = true
    }
  }
}

function closeCompareModal() {
  showCompareModal.value = false
  exitCompareMode()
}

function handleRestoreFromCompare(version) {
  closeCompareModal()
  selectedVersion.value = version
  initiateRestore(version)
}

function formatDate(dateStr) {
  if (!dateStr) return 'Unknown'
  const date = new Date(dateStr)
  const now = new Date()
  const diff = now - date
  
  // Less than 1 hour ago
  if (diff < 3600000) {
    const mins = Math.floor(diff / 60000)
    return mins <= 1 ? 'Just now' : `${mins} minutes ago`
  }
  
  // Less than 24 hours ago
  if (diff < 86400000) {
    const hours = Math.floor(diff / 3600000)
    return `${hours} hour${hours > 1 ? 's' : ''} ago`
  }
  
  // Less than 7 days ago
  if (diff < 604800000) {
    const days = Math.floor(diff / 86400000)
    return `${days} day${days > 1 ? 's' : ''} ago`
  }
  
  // Format as date
  return date.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: date.getFullYear() !== now.getFullYear() ? 'numeric' : undefined,
    hour: '2-digit',
    minute: '2-digit',
  })
}

function formatEmail(email) {
  if (!email) return 'Unknown'
  return email.split('@')[0]
}
</script>

<style scoped>
.version-history-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  backdrop-filter: blur(4px);
  display: flex;
  justify-content: flex-end;
  z-index: 10000;
}

.version-history-panel {
  width: 420px;
  max-width: 100vw;
  height: 100%;
  background: var(--panel-bg, #ffffff);
  box-shadow: -4px 0 24px rgba(0, 0, 0, 0.15);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* CSS Variables for theming */
:root:not(.dark) .version-history-panel {
  --panel-bg: #ffffff;
  --header-bg: #f9fafb;
  --border-color: #e5e7eb;
  --border-light: #f3f4f6;
  --text-primary: #1f2937;
  --text-secondary: #6b7280;
  --text-muted: #9ca3af;
  --item-bg: #ffffff;
  --item-hover: #f9fafb;
  --icon-bg: #f3f4f6;
  --badge-bg: #f3f4f6;
}

:root.dark .version-history-panel,
.dark .version-history-panel {
  --panel-bg: #1f1f26;
  --header-bg: #26262e;
  --border-color: #3d3d47;
  --border-light: #32323c;
  --text-primary: #e0e0e0;
  --text-secondary: #a0a0a0;
  --text-muted: #6b6b6b;
  --item-bg: #26262e;
  --item-hover: #32323c;
  --icon-bg: #32323c;
  --badge-bg: #32323c;
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid var(--border-color);
  flex-shrink: 0;
}

.panel-header h2 {
  color: var(--text-primary);
}

.close-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: none;
  background: transparent;
  border-radius: 9999px;
  color: var(--text-secondary);
  cursor: pointer;
  transition: all 0.15s;
}

.close-btn:hover {
  background: var(--icon-bg);
  color: var(--text-primary);
}

.panel-actions {
  padding: 12px 20px;
  border-bottom: 1px solid var(--border-light);
  flex-shrink: 0;
}

.create-version-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  padding: 12px 16px;
  background: rgb(var(--color-primary-500));
  color: white;
  font-weight: 500;
  font-size: 14px;
  border: none;
  border-radius: 9999px;
  cursor: pointer;
  transition: all 0.15s;
}

.create-version-btn:hover:not(:disabled) {
  background: rgb(var(--color-primary-600));
}

.create-version-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.versions-list {
  flex: 1;
  overflow-y: auto;
  padding: 12px 0;
}

.loading-state,
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 48px 20px;
  text-align: center;
}

.loading-state span,
.empty-state p {
  color: var(--text-secondary);
}

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.versions-container {
  padding: 0 12px;
}

.current-version-badge {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 16px;
  margin-bottom: 8px;
  background: rgb(var(--color-primary-500) / 0.1);
  border-radius: 9999px;
}

.current-version-badge span {
  color: var(--text-secondary);
}

.compare-mode-banner {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 16px;
  margin-bottom: 8px;
  background: rgb(var(--color-primary-500) / 0.1);
  border: 1px solid rgb(var(--color-primary-300));
  border-radius: 9999px;
  color: var(--text-secondary);
}

.version-item {
  margin-bottom: 8px;
  border-radius: 12px;
  border: 1px solid var(--border-color);
  background: var(--item-bg);
  overflow: hidden;
  transition: all 0.2s;
}

.version-item:hover {
  border-color: var(--text-muted);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.version-item.selected {
  border-color: rgb(var(--color-primary-400));
  background: rgb(var(--color-primary-500) / 0.08);
}

.version-item.compare-selected {
  border-color: rgb(var(--color-primary-500));
  background: rgb(var(--color-primary-500) / 0.08);
}

.version-item.confirming {
  border-color: #f59e0b;
  background: rgba(245, 158, 11, 0.08);
}

.version-main {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  cursor: pointer;
}

.compare-checkbox {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border: 2px solid var(--border-color);
  border-radius: 6px;
  background: var(--panel-bg);
  flex-shrink: 0;
  transition: all 0.15s;
}

.compare-checkbox.checked {
  background: rgb(var(--color-primary-500));
  border-color: rgb(var(--color-primary-500));
}

.version-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  background: var(--icon-bg);
  border-radius: 10px;
  color: var(--text-secondary);
  flex-shrink: 0;
}

.version-item.selected .version-icon {
  background: rgb(var(--color-primary-500) / 0.2);
  color: rgb(var(--color-primary-500));
}

.version-info {
  flex: 1;
  min-width: 0;
}

.version-title {
  font-weight: 500;
  font-size: 14px;
  color: var(--text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.version-meta {
  display: flex;
  align-items: center;
  gap: 4px;
  margin-top: 2px;
  font-size: 12px;
}

.version-date {
  color: var(--text-secondary);
}

.version-separator {
  color: var(--text-muted);
}

.version-author {
  color: var(--text-secondary);
  font-weight: 500;
}

.version-number {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-muted);
  padding: 4px 10px;
  background: var(--badge-bg);
  border-radius: 9999px;
}

.version-actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 0 16px 14px;
}

.restore-confirm {
  padding: 12px 16px;
  background: rgba(245, 158, 11, 0.1);
  border-top: 1px solid #fcd34d;
}

.confirm-message {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #d97706;
  margin-bottom: 12px;
}

.confirm-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

.confirm-btn {
  padding: 8px 16px;
  font-size: 13px;
  font-weight: 500;
  border: none;
  border-radius: 9999px;
  cursor: pointer;
  transition: all 0.15s;
}

.confirm-btn.cancel {
  background: var(--icon-bg);
  color: var(--text-primary);
}

.confirm-btn.cancel:hover {
  background: var(--border-color);
}

.confirm-btn.confirm {
  background: rgb(var(--color-primary-500));
  color: white;
}

.confirm-btn.confirm:hover:not(:disabled) {
  background: rgb(var(--color-primary-600));
}

.confirm-btn.confirm:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.action-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 16px;
  font-size: 13px;
  font-weight: 500;
  border: none;
  border-radius: 9999px;
  cursor: pointer;
  transition: all 0.15s;
}

.action-btn.restore-btn {
  background: rgb(var(--color-primary-500));
  color: white;
}

.action-btn.restore-btn:hover:not(:disabled) {
  background: rgb(var(--color-primary-600));
}

.action-btn.compare-btn {
  background: var(--icon-bg);
  color: var(--text-primary);
}

.action-btn.compare-btn:hover {
  background: var(--border-color);
}

.panel-footer {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 20px;
  border-top: 1px solid var(--border-color);
  background: var(--header-bg);
  flex-shrink: 0;
}

.panel-footer span {
  color: var(--text-muted);
}

/* Transitions */
.slide-panel-enter-active,
.slide-panel-leave-active {
  transition: opacity 0.3s ease;
}

.slide-panel-enter-active .version-history-panel,
.slide-panel-leave-active .version-history-panel {
  transition: transform 0.3s ease;
}

.slide-panel-enter-from,
.slide-panel-leave-to {
  opacity: 0;
}

.slide-panel-enter-from .version-history-panel,
.slide-panel-leave-to .version-history-panel {
  transform: translateX(100%);
}

.expand-enter-active,
.expand-leave-active {
  transition: all 0.2s ease;
  overflow: hidden;
}

.expand-enter-from,
.expand-leave-to {
  opacity: 0;
  max-height: 0;
  padding-top: 0;
  padding-bottom: 0;
}

.expand-enter-to,
.expand-leave-from {
  max-height: 200px;
}
</style>

