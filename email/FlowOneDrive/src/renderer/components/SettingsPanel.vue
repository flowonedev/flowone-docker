<script setup lang="ts">
import { ref, onMounted, computed, onUnmounted } from 'vue'
import { useConfigStore } from '../stores/config'
import { useWatchFoldersStore } from '../stores/watchFolders'
import WatchFolderManageModal from './WatchFolderManageModal.vue'

const configStore = useConfigStore()
const watchFoldersStore = useWatchFoldersStore()
const isSaving = ref(false)
const saveMessage = ref('')

// Local form state
const syncEnabled = ref(true)
const notificationsEnabled = ref(true)
const startMinimized = ref(false)
const launchAtStartup = ref(false)

// Lock screen settings
const lockEnabled = ref(false)
const lockTimeout = ref(5)
const lockOnMinimize = ref(false)
const hasPin = ref(false)
const biometricAvailable = ref(false)
const showSetPin = ref(false)
const showChangePin = ref(false)
const newPinValue = ref('')
const confirmPinValue = ref('')
const oldPinValue = ref('')
const newPinChange = ref('')
const confirmPinChange = ref('')
const pinError = ref('')
const pinSaving = ref(false)


// Time tracking settings
const timeTrackingEnabled = ref(true)
const handleMonitorEnabled = ref(true)
const windowMonitorEnabled = ref(true)

// Printer Discovery
const localPrinters = ref<Array<{ name: string; displayName: string; status: number; isDefault: boolean; source: string }>>([])
const networkPrinters = ref<Array<{ name: string; displayName: string; status: number; ip?: string; port?: number; protocol?: string; model?: string; location?: string; mac?: string; source: string }>>([])
const printersLoading = ref(false)
const printersError = ref('')
const printerTestName = ref('')
const printerTestStatus = ref('')
const printerTestBusy = ref(false)
const scanProgress = ref({ scanned: 0, total: 0 })
let unsubScanProgress: (() => void) | null = null

// NAS Credentials
const nasUsername = ref('')
const nasPassword = ref('')
const useNasCredentials = ref(false)
const nasCredentialsSaving = ref(false)
const nasCredentialsMessage = ref('')
const nasTesting = ref(false)
const showNasPassword = ref(false)

// NAS/Access Mode status
const accessModeStatus = ref<{
  mode: 'direct-nas' | 'server-api' | 'offline'
  nasIp: string | null
  nasReachable: boolean
  serverUrl: string | null
  initialized: boolean
  pendingOfflineCount?: number
}>({
  mode: 'offline',
  nasIp: null,
  nasReachable: false,
  serverUrl: null,
  initialized: false,
  pendingOfflineCount: 0
})

// Cleanup function for event listeners
let unsubscribeModeChanged: (() => void) | null = null
let unsubscribeModeReady: (() => void) | null = null

onMounted(async () => {
  // Run every IPC in parallel so a single slow handler can't sequentially
  // block the others. Previously these were awaited one-by-one — when any
  // single call (e.g. Windows Hello detection in lock-get-settings) was
  // slow, the whole Settings tab froze for several seconds.
  await configStore.loadConfig()
  syncEnabled.value = configStore.config.syncEnabled
  notificationsEnabled.value = configStore.config.notificationsEnabled
  startMinimized.value = configStore.config.startMinimized
  launchAtStartup.value = configStore.config.launchAtStartup
  timeTrackingEnabled.value = configStore.config.timeTracking?.enabled ?? true
  handleMonitorEnabled.value = configStore.config.timeTracking?.handleMonitorEnabled ?? true
  windowMonitorEnabled.value = configStore.config.timeTracking?.windowMonitorEnabled ?? true

  // Cached store read — instant. Triggers no IPC.
  watchFoldersStore.loadWatchFolders()

  await Promise.allSettled([
    (async () => {
      try {
        const status = await window.api.getAccessModeStatus()
        accessModeStatus.value = status
      } catch (e) {
        console.error('Failed to load access mode status:', e)
      }
    })(),
    (async () => {
      try {
        const creds = await window.api.getNasCredentials()
        nasUsername.value = creds.username || ''
        useNasCredentials.value = creds.useCredentials || false
      } catch (e) {
        console.error('Failed to load NAS credentials:', e)
      }
    })(),
    (async () => {
      try {
        const lockSettings = await window.api.lock.getSettings()
        lockEnabled.value = lockSettings.lockEnabled
        lockTimeout.value = lockSettings.lockTimeout
        lockOnMinimize.value = lockSettings.lockOnMinimize
        hasPin.value = lockSettings.hasPin
        biometricAvailable.value = lockSettings.biometricAvailable
      } catch (e) {
        console.error('Failed to load lock settings:', e)
      }
    })(),
  ])

  unsubscribeModeChanged = window.api.onAccessModeChanged((data) => {
    accessModeStatus.value.mode = data.mode as any
    accessModeStatus.value.nasReachable = data.mode === 'direct-nas'
  })

  unsubscribeModeReady = window.api.onAccessModeReady(async (status) => {
    console.log('[SettingsPanel] AccessModeManager ready, updating status:', status)
    accessModeStatus.value = {
      ...accessModeStatus.value,
      ...status,
      initialized: true,
    }
  })
})

onUnmounted(() => {
  if (unsubscribeModeChanged) {
    unsubscribeModeChanged()
  }
  if (unsubscribeModeReady) {
    unsubscribeModeReady()
  }
})

// Check-button feedback. Without these the button used to await two IPCs
// silently with no UI signal and looked dead.
const connectionChecking = ref(false)
const connectionCheckResult = ref<{
  ok: boolean
  text: string
  detail: string
} | null>(null)

async function forceCheckConnection() {
  if (connectionChecking.value) return
  connectionChecking.value = true
  connectionCheckResult.value = null
  const t0 = Date.now()
  try {
    const result: any = await window.api.forceAccessModeCheck()
    const status = await window.api.getAccessModeStatus()
    accessModeStatus.value = status

    const elapsed = Date.now() - t0
    // New rich shape (mode + nasMs + serverMs); old shape was just a string.
    if (result && typeof result === 'object' && 'mode' in result) {
      const parts: string[] = []
      if (result.nasMs !== null && result.nasMs !== undefined) {
        parts.push(`NAS ${result.nasMs}ms`)
      } else if (result.nasIp) {
        parts.push('NAS unreachable')
      }
      if (result.serverMs !== null && result.serverMs !== undefined) {
        parts.push(`server ${result.serverMs}ms`)
      } else {
        parts.push('server unreachable')
      }
      const ok = result.mode !== 'offline'
      connectionCheckResult.value = {
        ok,
        text: ok ? `Connected — ${result.mode}` : 'Offline',
        detail: parts.join(' • ') + ` (${elapsed}ms total)`,
      }
    } else {
      const mode = String(result || 'offline')
      const ok = mode !== 'offline'
      connectionCheckResult.value = {
        ok,
        text: ok ? `Connected — ${mode}` : 'Offline',
        detail: `Probe took ${elapsed}ms`,
      }
    }
  } catch (e: any) {
    connectionCheckResult.value = {
      ok: false,
      text: 'Check failed',
      detail: e?.message || 'Unknown error',
    }
  } finally {
    connectionChecking.value = false
    setTimeout(() => {
      connectionCheckResult.value = null
    }, 8_000)
  }
}

// Printer functions
async function fetchPrinters() {
  printersLoading.value = true
  printersError.value = ''
  scanProgress.value = { scanned: 0, total: 0 }

  unsubScanProgress = window.api.printer.onScanProgress((data) => {
    scanProgress.value = data
  })

  try {
    const result = await window.api.printer.getAllPrinters()
    localPrinters.value = result.local || []
    networkPrinters.value = result.network || []
    if (localPrinters.value.length === 0 && networkPrinters.value.length === 0) {
      printersError.value = 'No printers found (local or network)'
    }
  } catch (e: any) {
    printersError.value = 'Failed to scan for printers'
    console.error('[Settings] Printer discovery failed:', e)
  } finally {
    printersLoading.value = false
    if (unsubScanProgress) {
      unsubScanProgress()
      unsubScanProgress = null
    }
  }
}

async function testPrint(printerName: string) {
  printerTestName.value = printerName
  printerTestBusy.value = true
  printerTestStatus.value = ''
  try {
    const html = `<html><body style="font-family:Arial,sans-serif;padding:40px;">
      <h1 style="color:#22c55e;">FlowOne Drive</h1>
      <p>Printer test page</p>
      <p>Printer: <strong>${printerName}</strong></p>
      <p>Date: ${new Date().toLocaleString()}</p>
      <hr/>
      <p style="color:#888;font-size:12px;">If you can read this, the printer is working correctly.</p>
    </body></html>`
    const result = await window.api.printer.printHtml(html, printerName, { copies: 1, silent: true })
    printerTestStatus.value = result.success ? 'Test page sent!' : (result.error || 'Print failed')
  } catch (e: any) {
    printerTestStatus.value = 'Print failed: ' + e.message
  } finally {
    printerTestBusy.value = false
    setTimeout(() => { printerTestStatus.value = ''; printerTestName.value = '' }, 5000)
  }
}

function printerStatusLabel(status: number): string {
  switch (status) {
    case 0: return 'Idle'
    case 1: return 'Printing'
    case 2: return 'Error'
    default: return 'Unknown'
  }
}

function printerStatusColor(status: number): string {
  switch (status) {
    case 0: return '#22c55e'
    case 1: return '#f59e0b'
    case 2: return '#ef4444'
    default: return '#6b7280'
  }
}

// NAS Credentials functions
async function saveNasCredentials() {
  if (!nasUsername.value) {
    nasCredentialsMessage.value = 'Username is required'
    return
  }
  
  nasCredentialsSaving.value = true
  nasCredentialsMessage.value = ''
  
  try {
    const result = await window.api.saveNasCredentials(
      nasUsername.value, 
      nasPassword.value, 
      useNasCredentials.value
    )
    
    if (result.success) {
      nasCredentialsMessage.value = 'Credentials saved'
      nasPassword.value = '' // Clear password field after save
      setTimeout(() => { nasCredentialsMessage.value = '' }, 3000)
      
      // Refresh connection status
      await forceCheckConnection()
    } else {
      nasCredentialsMessage.value = result.error || 'Failed to save'
    }
  } catch (e: any) {
    nasCredentialsMessage.value = 'Failed to save credentials'
  } finally {
    nasCredentialsSaving.value = false
  }
}

async function testNasCredentials() {
  if (!nasUsername.value || !nasPassword.value) {
    nasCredentialsMessage.value = 'Enter username and password to test'
    return
  }
  
  nasTesting.value = true
  nasCredentialsMessage.value = 'Testing...'
  
  try {
    const result = await window.api.testNasCredentials(nasUsername.value, nasPassword.value)
    
    if (result.success) {
      nasCredentialsMessage.value = 'Credentials verified!'
    } else {
      nasCredentialsMessage.value = result.error || 'Test failed'
    }
    setTimeout(() => { nasCredentialsMessage.value = '' }, 5000)
  } catch (e: any) {
    nasCredentialsMessage.value = 'Test failed'
  } finally {
    nasTesting.value = false
  }
}

async function clearNasCredentials() {
  try {
    await window.api.clearNasCredentials()
    nasUsername.value = ''
    nasPassword.value = ''
    useNasCredentials.value = false
    nasCredentialsMessage.value = 'Credentials cleared'
    setTimeout(() => { nasCredentialsMessage.value = '' }, 3000)
  } catch (e) {
    nasCredentialsMessage.value = 'Failed to clear'
  }
}

// Computed for access mode display
const accessModeLabel = computed(() => {
  switch (accessModeStatus.value.mode) {
    case 'direct-nas': return 'Direct NAS'
    case 'server-api': return 'Server Relay'
    case 'offline': return 'Offline'
    default: return 'Unknown'
  }
})

const accessModeColor = computed(() => {
  switch (accessModeStatus.value.mode) {
    case 'direct-nas': return '#22c55e' // Green - fastest
    case 'server-api': return '#f59e0b' // Amber - working but slower
    case 'offline': return '#ef4444' // Red - no connection
    default: return '#6b7280'
  }
})

const accessModeIcon = computed(() => {
  switch (accessModeStatus.value.mode) {
    case 'direct-nas': return 'lan'
    case 'server-api': return 'cloud'
    case 'offline': return 'cloud_off'
    default: return 'help'
  }
})

// Lock settings functions
async function toggleLockEnabled() {
  if (!hasPin.value) {
    showSetPin.value = true
    return
  }
  const newValue = !lockEnabled.value
  const result = await window.api.lock.setSettings({ lockEnabled: newValue })
  if (result.success) {
    lockEnabled.value = newValue
  }
}

async function toggleLockOnMinimize() {
  const newValue = !lockOnMinimize.value
  const result = await window.api.lock.setSettings({ lockOnMinimize: newValue })
  if (result.success) {
    lockOnMinimize.value = newValue
  }
}

async function updateLockTimeout(minutes: number) {
  const result = await window.api.lock.setSettings({ lockTimeout: minutes })
  if (result.success) {
    lockTimeout.value = minutes
  }
}

async function handleSetPinSubmit() {
  pinError.value = ''
  if (!newPinValue.value || newPinValue.value.length !== 4) {
    pinError.value = 'PIN must be exactly 4 digits'
    return
  }
  if (!/^\d{4}$/.test(newPinValue.value)) {
    pinError.value = 'PIN must contain only digits'
    return
  }
  if (newPinValue.value !== confirmPinValue.value) {
    pinError.value = 'PINs do not match'
    return
  }
  
  pinSaving.value = true
  try {
    const result = await window.api.lock.setPin(newPinValue.value)
    if (result.success) {
      await window.api.lock.setSettings({ lockEnabled: true })
      hasPin.value = true
      lockEnabled.value = true
      showSetPin.value = false
      newPinValue.value = ''
      confirmPinValue.value = ''
    } else {
      pinError.value = result.message || 'Failed to set PIN'
    }
  } catch (e) {
    pinError.value = 'Failed to set PIN'
  } finally {
    pinSaving.value = false
  }
}

async function handleChangePinSubmit() {
  pinError.value = ''
  if (!oldPinValue.value) {
    pinError.value = 'Enter your current PIN'
    return
  }
  if (!newPinChange.value || newPinChange.value.length !== 4) {
    pinError.value = 'New PIN must be exactly 4 digits'
    return
  }
  if (!/^\d{4}$/.test(newPinChange.value)) {
    pinError.value = 'PIN must contain only digits'
    return
  }
  if (newPinChange.value !== confirmPinChange.value) {
    pinError.value = 'New PINs do not match'
    return
  }
  
  pinSaving.value = true
  try {
    const verifyResult = await window.api.lock.verifyPin(oldPinValue.value)
    if (!verifyResult?.success) {
      pinError.value = verifyResult?.locked
        ? `Too many attempts. Try again in ${Math.ceil((verifyResult.retryAfterMs || 30000) / 1000)}s`
        : 'Current PIN is incorrect'
      pinSaving.value = false
      return
    }
    const result = await window.api.lock.setPin(newPinChange.value)
    if (result.success) {
      showChangePin.value = false
      oldPinValue.value = ''
      newPinChange.value = ''
      confirmPinChange.value = ''
    } else {
      pinError.value = result.message || 'Failed to set new PIN'
    }
  } catch (e: any) {
    console.error('[Settings] Change PIN error:', e)
    pinError.value = 'Failed to change PIN'
  } finally {
    pinSaving.value = false
  }
}

async function removeLockPin() {
  if (!confirm('Remove your lock PIN? This will disable the app lock feature.')) return
  await window.api.lock.removePin()
  hasPin.value = false
  lockEnabled.value = false
}

async function lockNow() {
  await window.api.lock.lockNow()
}

async function saveSettings() {
  isSaving.value = true
  saveMessage.value = ''
  
  try {
    await configStore.saveConfig({
      syncEnabled: syncEnabled.value,
      notificationsEnabled: notificationsEnabled.value,
      startMinimized: startMinimized.value,
      launchAtStartup: launchAtStartup.value,
      timeTracking: {
        enabled: timeTrackingEnabled.value,
        handleMonitorEnabled: handleMonitorEnabled.value,
        windowMonitorEnabled: windowMonitorEnabled.value,
        handlePollInterval: configStore.config.timeTracking?.handlePollInterval || 1000,
        windowPollInterval: configStore.config.timeTracking?.windowPollInterval || 1000,
        inactivityTimeout: configStore.config.timeTracking?.inactivityTimeout || 300,
      },
    })
    saveMessage.value = 'Settings saved successfully'
    setTimeout(() => { saveMessage.value = '' }, 3000)
  } catch (e: any) {
    saveMessage.value = 'Failed to save settings'
  } finally {
    isSaving.value = false
  }
}

async function chooseSyncFolder() {
  try {
    const result = await window.api.selectSyncFolder()
    if (result) {
      await configStore.loadConfig()
    }
  } catch (e) {
    console.error('Failed to choose sync folder:', e)
  }
}

async function openSyncFolder() {
  await window.api.openSyncFolder()
}

// Wave D.7: clicking a watch folder row opens the in-app management modal
// (project/board info, change local folder, remove). The open_in_new icon
// still deep-links to the relevant cloud board.
const managedWatchFolder = ref<any | null>(null)

function openWatchFolderInCloud(wf: { boardId: number | null }) {
  const apiUrl = configStore.config.apiUrl || ''
  const origin = apiUrl
    ? apiUrl.replace(/\/api\/?$/i, '').replace(/\/$/, '')
    : ''
  if (!origin) return
  const url = wf.boardId
    ? `${origin}/boards/${wf.boardId}`
    : `${origin}/boards`
  window.api.openExternalUrl(url).catch((err: unknown) => {
    console.error('Failed to open watch folder in cloud:', err)
  })
}
</script>

<template>
  <div class="flex-1 flex flex-col overflow-hidden" style="background: var(--bg-main);">
    <!-- Header -->
    <div style="height: 56px; border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 20px; background: var(--bg-main);">
      <span class="material-symbols-rounded" style="font-size: 20px; color: #22c55e; margin-right: 10px;">settings</span>
      <h2 style="color: var(--text-primary); font-size: 16px; font-weight: 500;">Settings</h2>
    </div>
    
    <!-- Settings content -->
    <div style="flex: 1; overflow-y: auto; padding: 20px;">
      <div style="max-width: 600px;">
        <!-- Account section -->
        <div style="margin-bottom: 32px;">
          <h3 style="color: var(--text-primary); font-size: 14px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span class="material-symbols-rounded" style="font-size: 18px; color: #22c55e;">person</span>
            Account
          </h3>
          <div style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); padding: 16px;">
            <div style="display: flex; align-items: center; gap: 12px;">
              <div style="width: 48px; height: 48px; border-radius: 50%; background: #22c55e; display: flex; align-items: center; justify-content: center;">
                <span style="color: var(--text-primary); font-weight: 600; font-size: 18px;">
                  {{ (configStore.config.userEmail || 'U')[0].toUpperCase() }}
                </span>
              </div>
              <div>
                <p style="color: var(--text-primary); font-weight: 500;">{{ configStore.config.userEmail || 'Not logged in' }}</p>
                <p style="color: var(--text-dim); font-size: 12px;">{{ configStore.config.apiUrl }}</p>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Connection Status Section -->
        <div style="margin-bottom: 32px;">
          <h3 style="color: var(--text-primary); font-size: 14px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span class="material-symbols-rounded" style="font-size: 18px; color: #22c55e;">wifi</span>
            Connection Status
          </h3>
          <div style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); padding: 16px;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
              <div 
                :style="{ 
                  width: '48px', 
                  height: '48px', 
                  borderRadius: '50%', 
                  background: accessModeColor, 
                  display: 'flex', 
                  alignItems: 'center', 
                  justifyContent: 'center' 
                }"
              >
                <span class="material-symbols-rounded" style="color: var(--text-primary); font-size: 24px;">
                  {{ accessModeIcon }}
                </span>
              </div>
              <div style="flex: 1;">
                <p style="color: var(--text-primary); font-weight: 500; font-size: 14px;">{{ accessModeLabel }}</p>
                <p style="color: var(--text-dim); font-size: 12px;">
                  <template v-if="accessModeStatus.mode === 'direct-nas'">
                    Connected to NAS at {{ accessModeStatus.nasIp }}
                  </template>
                  <template v-else-if="accessModeStatus.mode === 'server-api'">
                    Using server relay (NAS not directly reachable)
                  </template>
                  <template v-else>
                    No connection - changes will sync when online
                  </template>
                </p>
              </div>
              <button 
                @click="forceCheckConnection"
                :disabled="connectionChecking"
                style="padding: 8px 12px; border-radius: 8px; background: var(--bg-elevated); color: var(--text-primary); font-size: 12px; display: flex; align-items: center; gap: 4px;"
                class="hover:bg-[--bg-elevated-hover] transition-colors disabled:opacity-60"
              >
                <span 
                  class="material-symbols-rounded" 
                  :class="connectionChecking ? 'animate-spin' : ''"
                  style="font-size: 16px;"
                >{{ connectionChecking ? 'progress_activity' : 'refresh' }}</span>
                {{ connectionChecking ? 'Checking…' : 'Check' }}
              </button>
            </div>

            <!-- Result row from the last Check click. Auto-hides after 8s. -->
            <div 
              v-if="connectionCheckResult"
              :style="{
                marginTop: '12px',
                padding: '10px 12px',
                borderRadius: '8px',
                background: connectionCheckResult.ok ? 'rgba(34, 197, 94, 0.10)' : 'rgba(239, 68, 68, 0.10)',
                border: `1px solid ${connectionCheckResult.ok ? 'rgba(34, 197, 94, 0.30)' : 'rgba(239, 68, 68, 0.30)'}`,
                display: 'flex',
                alignItems: 'center',
                gap: '8px'
              }"
            >
              <span 
                class="material-symbols-rounded" 
                :style="{ fontSize: '18px', color: connectionCheckResult.ok ? '#22c55e' : '#ef4444' }"
              >{{ connectionCheckResult.ok ? 'check_circle' : 'error' }}</span>
              <div style="flex: 1; min-width: 0;">
                <p :style="{ fontSize: '12px', fontWeight: '500', color: connectionCheckResult.ok ? '#22c55e' : '#ef4444' }">
                  {{ connectionCheckResult.text }}
                </p>
                <p style="font-size: 11px; color: var(--text-dim); margin-top: 2px;">
                  {{ connectionCheckResult.detail }}
                </p>
              </div>
            </div>
            <!-- Pending offline queue indicator -->
            <div 
              v-if="accessModeStatus.pendingOfflineCount && accessModeStatus.pendingOfflineCount > 0"
              style="background: var(--info-bg); border: 1px solid #f59e0b30; border-radius: 8px; padding: 12px; margin-bottom: 12px; display: flex; align-items: center; gap: 10px;"
            >
              <span class="material-symbols-rounded" style="font-size: 20px; color: #f59e0b;">pending_actions</span>
              <div>
                <p style="color: #f59e0b; font-size: 12px; font-weight: 500;">
                  {{ accessModeStatus.pendingOfflineCount }} change{{ accessModeStatus.pendingOfflineCount > 1 ? 's' : '' }} queued
                </p>
                <p style="color: var(--text-muted); font-size: 11px;">Will sync automatically when online</p>
              </div>
            </div>
            
            <!-- Additional info -->
            <div style="display: flex; flex-wrap: wrap; gap: 16px; padding-top: 12px; border-top: 1px solid var(--border);">
              <div v-if="accessModeStatus.nasIp" style="display: flex; align-items: center; gap: 6px;">
                <span class="material-symbols-rounded" style="font-size: 16px; color: var(--text-dim);">storage</span>
                <span style="color: var(--text-muted); font-size: 12px;">NAS: {{ accessModeStatus.nasIp }}</span>
                <span 
                  :style="{ 
                    width: '8px', 
                    height: '8px', 
                    borderRadius: '50%', 
                    background: accessModeStatus.nasReachable ? '#22c55e' : '#ef4444' 
                  }"
                ></span>
              </div>
              <div v-if="accessModeStatus.serverUrl" style="display: flex; align-items: center; gap: 6px;">
                <span class="material-symbols-rounded" style="font-size: 16px; color: var(--text-dim);">cloud</span>
                <span style="color: var(--text-muted); font-size: 12px;">{{ accessModeStatus.serverUrl.replace('https://', '').replace('/api', '') }}</span>
              </div>
            </div>
          </div>
        </div>
        
        <!-- NAS Credentials Section -->
        <div v-if="accessModeStatus.nasIp" style="margin-bottom: 32px;">
          <h3 style="color: var(--text-primary); font-size: 14px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span class="material-symbols-rounded" style="font-size: 18px; color: #22c55e;">key</span>
            NAS Credentials
          </h3>
          <div style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); padding: 16px;">
            <p style="color: var(--text-muted); font-size: 12px; margin-bottom: 16px;">
              Store NAS credentials for automatic authentication when accessing the NAS directly.
              Credentials are encrypted and stored locally.
            </p>
            
            <!-- Use credentials toggle -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--border);">
              <div>
                <p style="color: var(--text-primary); font-size: 13px;">Use stored credentials</p>
                <p style="color: var(--text-dim); font-size: 11px;">Instead of Windows Credential Manager</p>
              </div>
              <button
                @click="useNasCredentials = !useNasCredentials"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none',
                  useNasCredentials ? 'bg-primary-500' : 'bg-surface-600'
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5',
                    useNasCredentials ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
                  ]"
                />
              </button>
            </div>
            
            <!-- Credentials form -->
            <div v-if="useNasCredentials" style="display: flex; flex-direction: column; gap: 12px;">
              <!-- Username -->
              <div>
                <label style="color: var(--text-muted); font-size: 11px; display: block; margin-bottom: 4px;">Username</label>
                <input 
                  v-model="nasUsername"
                  type="text"
                  placeholder="NAS username"
                  style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg-deep); border: 1px solid var(--border); color: var(--text-primary); font-size: 13px; outline: none;"
                  class="focus:border-primary-500"
                />
              </div>
              
              <!-- Password -->
              <div>
                <label style="color: var(--text-muted); font-size: 11px; display: block; margin-bottom: 4px;">Password</label>
                <div style="position: relative;">
                  <input 
                    v-model="nasPassword"
                    :type="showNasPassword ? 'text' : 'password'"
                    placeholder="Enter password to update"
                    style="width: 100%; padding: 10px 40px 10px 12px; border-radius: 8px; background: var(--bg-deep); border: 1px solid var(--border); color: var(--text-primary); font-size: 13px; outline: none;"
                    class="focus:border-primary-500"
                  />
                  <button 
                    @click="showNasPassword = !showNasPassword"
                    style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; padding: 4px;"
                  >
                    <span class="material-symbols-rounded" style="font-size: 18px; color: var(--text-dim);">
                      {{ showNasPassword ? 'visibility_off' : 'visibility' }}
                    </span>
                  </button>
                </div>
              </div>
              
              <!-- Action buttons -->
              <div style="display: flex; gap: 8px; margin-top: 8px;">
                <button 
                  @click="testNasCredentials"
                  :disabled="nasTesting || !nasUsername || !nasPassword"
                  style="flex: 1; padding: 10px; border-radius: 8px; background: var(--bg-elevated); color: var(--text-primary); font-size: 13px; display: flex; align-items: center; justify-content: center; gap: 6px;"
                  class="hover:bg-[--bg-elevated-hover] transition-colors disabled:opacity-50"
                >
                  <span v-if="nasTesting" class="material-symbols-rounded animate-spin" style="font-size: 16px;">sync</span>
                  <span v-else class="material-symbols-rounded" style="font-size: 16px;">verified</span>
                  Test
                </button>
                <button 
                  @click="saveNasCredentials"
                  :disabled="nasCredentialsSaving || !nasUsername"
                  style="flex: 1; padding: 10px; border-radius: 8px; background: #22c55e; color: var(--text-primary); font-size: 13px; display: flex; align-items: center; justify-content: center; gap: 6px;"
                  class="hover:bg-[#15803d] transition-colors disabled:opacity-50"
                >
                  <span v-if="nasCredentialsSaving" class="material-symbols-rounded animate-spin" style="font-size: 16px;">sync</span>
                  <span v-else class="material-symbols-rounded" style="font-size: 16px;">save</span>
                  Save
                </button>
                <button 
                  @click="clearNasCredentials"
                  style="padding: 10px; border-radius: 8px; background: var(--bg-elevated); color: #ef4444; font-size: 13px; display: flex; align-items: center; justify-content: center;"
                  class="hover:bg-[--bg-elevated-hover] transition-colors"
                >
                  <span class="material-symbols-rounded" style="font-size: 16px;">delete</span>
                </button>
              </div>
              
              <!-- Status message -->
              <p 
                v-if="nasCredentialsMessage" 
                :style="{ 
                  fontSize: '12px', 
                  color: nasCredentialsMessage.includes('Failed') || nasCredentialsMessage.includes('required') ? '#ef4444' : '#22c55e',
                  marginTop: '4px'
                }"
              >
                {{ nasCredentialsMessage }}
              </p>
            </div>
            
            <!-- Info when not using stored credentials -->
            <div v-else style="background: var(--bg-deep); border-radius: 8px; padding: 12px; display: flex; align-items: start; gap: 10px;">
              <span class="material-symbols-rounded" style="font-size: 18px; color: var(--text-dim);">info</span>
              <p style="color: var(--text-muted); font-size: 12px; line-height: 1.5;">
                Using Windows Credential Manager for NAS authentication. 
                To add credentials there: Press Win+R, type <code style="background: var(--bg-elevated); padding: 2px 6px; border-radius: 4px;">control /name Microsoft.CredentialManager</code>, 
                then add a Windows Credential for <strong>{{ accessModeStatus.nasIp }}</strong>.
              </p>
            </div>
          </div>
        </div>
        
        <!-- Sync folder section -->
        <div style="margin-bottom: 32px;">
          <h3 style="color: var(--text-primary); font-size: 14px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span class="material-symbols-rounded" style="font-size: 18px; color: #22c55e;">folder</span>
            Sync Folder
          </h3>
          <div style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); padding: 16px;">
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
              <span class="material-symbols-rounded" style="font-size: 24px; color: #22c55e;">folder_open</span>
              <div style="flex: 1; min-width: 0;">
                <p style="color: var(--text-primary); font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                  {{ configStore.config.syncFolder || 'No folder selected' }}
                </p>
              </div>
            </div>
            <div style="display: flex; gap: 8px;">
              <button 
                @click="chooseSyncFolder"
                style="flex: 1; padding: 10px; border-radius: 8px; background: var(--bg-elevated); color: var(--text-primary); font-size: 13px; display: flex; align-items: center; justify-content: center; gap: 6px;"
                class="hover:bg-[--bg-elevated-hover] transition-colors"
              >
                <span class="material-symbols-rounded" style="font-size: 18px;">folder_open</span>
                Change Folder
              </button>
              <button 
                @click="openSyncFolder"
                style="padding: 10px 16px; border-radius: 8px; background: #22c55e; color: var(--text-primary); font-size: 13px; display: flex; align-items: center; gap: 6px;"
                class="hover:bg-[#15803d] transition-colors"
              >
                <span class="material-symbols-rounded" style="font-size: 18px;">open_in_new</span>
                Open
              </button>
            </div>
          </div>
        </div>
        
        <!-- General settings -->
        <div style="margin-bottom: 32px;">
          <h3 style="color: var(--text-primary); font-size: 14px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span class="material-symbols-rounded" style="font-size: 18px; color: #22c55e;">tune</span>
            General
          </h3>
          <div style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); overflow: hidden;">
            <!-- Sync enabled -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border);">
              <div>
                <p style="color: var(--text-primary); font-size: 13px;">Enable sync</p>
                <p style="color: var(--text-dim); font-size: 11px;">Automatically sync files with cloud</p>
              </div>
              <button
                @click="syncEnabled = !syncEnabled"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none',
                  syncEnabled ? 'bg-primary-500' : 'bg-surface-600'
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5',
                    syncEnabled ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
                  ]"
                />
              </button>
            </div>
            
            <!-- Notifications -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border);">
              <div>
                <p style="color: var(--text-primary); font-size: 13px;">Notifications</p>
                <p style="color: var(--text-dim); font-size: 11px;">Show notifications for sync events</p>
              </div>
              <button
                @click="notificationsEnabled = !notificationsEnabled"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none',
                  notificationsEnabled ? 'bg-primary-500' : 'bg-surface-600'
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5',
                    notificationsEnabled ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
                  ]"
                />
              </button>
            </div>
            
            <!-- Start minimized -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border);">
              <div>
                <p style="color: var(--text-primary); font-size: 13px;">Start minimized</p>
                <p style="color: var(--text-dim); font-size: 11px;">Start in system tray</p>
              </div>
              <button
                @click="startMinimized = !startMinimized"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none',
                  startMinimized ? 'bg-primary-500' : 'bg-surface-600'
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5',
                    startMinimized ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
                  ]"
                />
              </button>
            </div>
            
            <!-- Launch at startup -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px;">
              <div>
                <p style="color: var(--text-primary); font-size: 13px;">Launch at startup</p>
                <p style="color: var(--text-dim); font-size: 11px;">Start automatically when you log in</p>
              </div>
              <button
                @click="launchAtStartup = !launchAtStartup"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none',
                  launchAtStartup ? 'bg-primary-500' : 'bg-surface-600'
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5',
                    launchAtStartup ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
                  ]"
                />
              </button>
            </div>
          </div>
        </div>
        
        <!-- Time Tracking settings -->
        <div style="margin-bottom: 32px;">
          <h3 style="color: var(--text-primary); font-size: 14px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span class="material-symbols-rounded" style="font-size: 18px; color: #22c55e;">schedule</span>
            Time Tracking
          </h3>
          <div style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); overflow: hidden;">
            <!-- Time tracking enabled -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border);">
              <div>
                <p style="color: var(--text-primary); font-size: 13px;">Enable time tracking</p>
                <p style="color: var(--text-dim); font-size: 11px;">Track time spent editing files</p>
              </div>
              <button
                @click="timeTrackingEnabled = !timeTrackingEnabled"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none',
                  timeTrackingEnabled ? 'bg-primary-500' : 'bg-surface-600'
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5',
                    timeTrackingEnabled ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
                  ]"
                />
              </button>
            </div>
            
            <!-- Handle monitor -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border);">
              <div>
                <p style="color: var(--text-primary); font-size: 13px;">Handle monitor</p>
                <p style="color: var(--text-dim); font-size: 11px;">Detect file access via system handles</p>
              </div>
              <button
                @click="handleMonitorEnabled = !handleMonitorEnabled"
                :disabled="!timeTrackingEnabled"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none',
                  handleMonitorEnabled && timeTrackingEnabled ? 'bg-primary-500' : 'bg-surface-600',
                  !timeTrackingEnabled ? 'opacity-50 cursor-not-allowed' : ''
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5',
                    handleMonitorEnabled && timeTrackingEnabled ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
                  ]"
                />
              </button>
            </div>
            
            <!-- Window monitor -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px;">
              <div>
                <p style="color: var(--text-primary); font-size: 13px;">Window monitor</p>
                <p style="color: var(--text-dim); font-size: 11px;">Detect file editing via window titles</p>
              </div>
              <button
                @click="windowMonitorEnabled = !windowMonitorEnabled"
                :disabled="!timeTrackingEnabled"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none',
                  windowMonitorEnabled && timeTrackingEnabled ? 'bg-primary-500' : 'bg-surface-600',
                  !timeTrackingEnabled ? 'opacity-50 cursor-not-allowed' : ''
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5',
                    windowMonitorEnabled && timeTrackingEnabled ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
                  ]"
                />
              </button>
            </div>
          </div>
        </div>
        
        <!-- Watch Folders (synced from cloud, tracked locally) -->
        <div style="margin-bottom: 32px;">
          <h3 style="color: var(--text-primary); font-size: 14px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span class="material-symbols-rounded" style="font-size: 18px; color: #F59E0B;">folder_eye</span>
            Watch Folders
          </h3>
          <div style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); overflow: hidden;">
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border);">
              <div>
                <p style="color: var(--text-primary); font-size: 13px;">{{ watchFoldersStore.watchingCount }} watching, {{ watchFoldersStore.unresolvedCount }} unresolved</p>
                <p style="color: var(--text-dim); font-size: 11px;">Created in the web app. Click a folder to see its project, change the local folder, or remove it.</p>
              </div>
              <button
                @click="watchFoldersStore.refresh()"
                style="display: flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 9999px; font-size: 12px; font-weight: 500; border: 1px solid var(--border); background: transparent; color: var(--text-dim); cursor: pointer; transition: all 0.2s;"
                class="hover:opacity-80"
              >
                <span class="material-symbols-rounded" style="font-size: 16px;">refresh</span>
                Refresh
              </button>
            </div>

            <div v-if="watchFoldersStore.watchFolders.length === 0" style="padding: 24px 16px; text-align: center;">
              <span class="material-symbols-rounded" style="font-size: 32px; color: var(--text-ghost);">folder_off</span>
              <p style="color: var(--text-dim); font-size: 12px; margin-top: 8px;">No watch folders assigned</p>
              <p style="color: var(--text-ghost); font-size: 11px; margin-top: 4px;">Create watch folders in the cloud app (board header or card Assets tab)</p>
            </div>

            <div v-for="(wf, idx) in watchFoldersStore.watchFolders" :key="wf.id"
              @click="managedWatchFolder = wf"
              :title="`Click to manage (change folder, remove)`"
              :style="{
                display: 'flex', alignItems: 'center', gap: '10px', padding: '12px 16px', cursor: 'pointer',
                borderBottom: idx < watchFoldersStore.watchFolders.length - 1 ? '1px solid var(--border)' : 'none'
              }"
              class="hover:bg-[--bg-elevated] transition-colors">
              <span class="material-symbols-rounded" style="font-size: 18px;" :style="{ color: wf.status === 'watching' ? '#F59E0B' : '#EF4444' }">
                {{ wf.status === 'watching' ? 'folder_eye' : 'folder_off' }}
              </span>
              <div style="flex: 1; min-width: 0;">
                <div style="font-size: 13px; color: var(--text-primary); font-weight: 500;">{{ wf.name }}</div>
                <div v-if="wf.clientName || wf.boardName" style="font-size: 11px; color: var(--text-dim);">{{ wf.clientName }}{{ wf.boardName ? ' / ' + wf.boardName : '' }}</div>
                <div style="font-size: 10px; color: var(--text-ghost); margin-top: 2px; font-family: monospace;">{{ wf.resolvedPath || wf.folderPath }}</div>
              </div>
              <span v-if="wf.status === 'watching'" style="font-size: 10px; padding: 2px 8px; border-radius: 9999px; background: rgba(34,197,94,0.15); color: #22C55E; font-weight: 500;">watching</span>
              <span v-else-if="wf.status === 'not_found'" style="font-size: 10px; padding: 2px 8px; border-radius: 9999px; background: rgba(239,68,68,0.15); color: #EF4444; font-weight: 500;">not found</span>
              <span v-else style="font-size: 10px; padding: 2px 8px; border-radius: 9999px; background: rgba(245,158,11,0.15); color: #F59E0B; font-weight: 500;">pending</span>
              <span @click.stop="openWatchFolderInCloud(wf)" title="Open board in the web app"
                class="material-symbols-rounded hover:opacity-100" style="font-size: 16px; color: var(--text-ghost); opacity: 0.6;">open_in_new</span>
            </div>
          </div>
          <p v-if="watchFoldersStore.unresolvedCount > 0" style="font-size: 11px; color: var(--text-dim); margin-top: 8px;">
            <span class="material-symbols-rounded" style="font-size: 13px; vertical-align: -2px;">info</span>
            Unresolved folders: the path doesn't exist on this machine. Ask your admin to add a path override for you in the cloud app.
          </p>
        </div>

        <!-- Printer Discovery -->
        <div style="margin-bottom: 32px;">
          <h3 style="color: var(--text-primary); font-size: 14px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span class="material-symbols-rounded" style="font-size: 18px; color: #22c55e;">print</span>
            Printers
          </h3>

          <!-- Scan button bar -->
          <div style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); overflow: hidden; margin-bottom: 12px;">
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px;">
              <div>
                <p style="color: var(--text-primary); font-size: 13px;">Printer Discovery</p>
                <p style="color: var(--text-dim); font-size: 11px;">
                  <template v-if="printersLoading && scanProgress.total > 0">
                    Scanning network... {{ scanProgress.scanned }}/{{ scanProgress.total }} hosts
                  </template>
                  <template v-else-if="printersLoading">
                    Scanning local and network printers...
                  </template>
                  <template v-else-if="localPrinters.length + networkPrinters.length > 0">
                    {{ localPrinters.length + networkPrinters.length }} printer{{ (localPrinters.length + networkPrinters.length) !== 1 ? 's' : '' }} found
                  </template>
                  <template v-else>
                    Scans installed printers and the local network (ports 9100, 631, 515)
                  </template>
                </p>
              </div>
              <button
                @click="fetchPrinters"
                :disabled="printersLoading"
                style="display: flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 9999px; font-size: 12px; font-weight: 500; border: 1px solid var(--border); background: transparent; color: #22c55e; cursor: pointer; transition: all 0.2s;"
                :style="printersLoading ? 'opacity: 0.5; cursor: wait;' : ''"
                onmouseover="this.style.background='#22c55e20'"
                onmouseout="this.style.background='transparent'"
              >
                <span class="material-symbols-rounded" :style="printersLoading ? 'animation: spin 1s linear infinite;' : ''" style="font-size: 16px;">{{ printersLoading ? 'progress_activity' : 'radar' }}</span>
                {{ printersLoading ? 'Scanning...' : 'Scan Network' }}
              </button>
            </div>

            <!-- Progress bar during scan -->
            <div v-if="printersLoading && scanProgress.total > 0" style="padding: 0 16px 12px 16px;">
              <div style="height: 3px; background: var(--bg-elevated); border-radius: 2px; overflow: hidden;">
                <div :style="{ width: Math.round((scanProgress.scanned / scanProgress.total) * 100) + '%', height: '100%', background: '#22c55e', borderRadius: '2px', transition: 'width 0.3s ease' }"></div>
              </div>
            </div>
          </div>

          <!-- Error message -->
          <div v-if="printersError && localPrinters.length === 0 && networkPrinters.length === 0"
            style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); padding: 12px 16px; display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
            <span class="material-symbols-rounded" style="font-size: 16px; color: #f59e0b;">warning</span>
            <p style="color: #f59e0b; font-size: 12px;">{{ printersError }}</p>
          </div>

          <!-- Installed Printers -->
          <div v-if="localPrinters.length > 0" style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); overflow: hidden; margin-bottom: 12px;">
            <div style="padding: 10px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 6px;">
              <span class="material-symbols-rounded" style="font-size: 14px; color: var(--text-dim);">computer</span>
              <p style="color: var(--text-dim); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Installed Printers</p>
              <span style="margin-left: auto; font-size: 10px; color: var(--text-ghost); background: var(--bg-elevated); padding: 1px 6px; border-radius: 9999px;">{{ localPrinters.length }}</span>
            </div>
            <div v-for="(printer, idx) in localPrinters" :key="'local-' + printer.name"
              :style="{
                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                padding: '12px 16px',
                borderBottom: idx < localPrinters.length - 1 ? '1px solid var(--border)' : 'none'
              }">
              <div style="display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1;">
                <span class="material-symbols-rounded" style="font-size: 20px; color: var(--text-dim); flex-shrink: 0;">print</span>
                <div style="min-width: 0; flex: 1;">
                  <p style="color: var(--text-primary); font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    {{ printer.displayName || printer.name }}
                    <span v-if="printer.isDefault" style="margin-left: 6px; padding: 1px 6px; border-radius: 9999px; font-size: 9px; background: #22c55e20; color: #22c55e; font-weight: 600;">DEFAULT</span>
                  </p>
                  <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0;" :style="{ background: printerStatusColor(printer.status) }"></span>
                    <p style="color: var(--text-dim); font-size: 11px;">{{ printerStatusLabel(printer.status) }}</p>
                  </div>
                </div>
              </div>
              <div style="display: flex; align-items: center; gap: 6px; flex-shrink: 0;">
                <span v-if="printerTestName === printer.name && printerTestStatus" style="font-size: 11px; margin-right: 4px;"
                  :style="{ color: printerTestStatus.startsWith('Test page') ? '#22c55e' : '#ef4444' }">
                  {{ printerTestStatus }}
                </span>
                <button
                  @click="testPrint(printer.name)"
                  :disabled="printerTestBusy && printerTestName === printer.name"
                  style="display: flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 9999px; font-size: 11px; border: 1px solid var(--border); background: transparent; color: var(--text-muted); cursor: pointer; transition: all 0.2s;"
                  :style="printerTestBusy && printerTestName === printer.name ? 'opacity: 0.5; cursor: wait;' : ''"
                  onmouseover="this.style.borderColor='#22c55e'; this.style.color='#22c55e'"
                  onmouseout="this.style.borderColor='var(--border)'; this.style.color='var(--text-muted)'"
                >
                  <span class="material-symbols-rounded" style="font-size: 14px;">{{ printerTestBusy && printerTestName === printer.name ? 'progress_activity' : 'print_connect' }}</span>
                  Test
                </button>
              </div>
            </div>
          </div>

          <!-- Network Printers -->
          <div v-if="networkPrinters.length > 0" style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); overflow: hidden; margin-bottom: 12px;">
            <div style="padding: 10px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 6px;">
              <span class="material-symbols-rounded" style="font-size: 14px; color: var(--text-dim);">lan</span>
              <p style="color: var(--text-dim); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Network Printers</p>
              <span style="margin-left: auto; font-size: 10px; color: var(--text-ghost); background: var(--bg-elevated); padding: 1px 6px; border-radius: 9999px;">{{ networkPrinters.length }}</span>
            </div>
            <div v-for="(printer, idx) in networkPrinters" :key="'net-' + printer.ip"
              :style="{
                display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                padding: '12px 16px',
                borderBottom: idx < networkPrinters.length - 1 ? '1px solid var(--border)' : 'none'
              }">
              <div style="display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1;">
                <span class="material-symbols-rounded" style="font-size: 20px; color: #3b82f6; flex-shrink: 0;">lan</span>
                <div style="min-width: 0; flex: 1;">
                  <p style="color: var(--text-primary); font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    {{ printer.displayName || printer.name }}
                  </p>
                  <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <span style="color: var(--text-dim); font-size: 11px;">{{ printer.ip }}:{{ printer.port }}</span>
                    <span style="padding: 0px 5px; border-radius: 9999px; font-size: 9px; background: #3b82f620; color: #60a5fa; font-weight: 500;">{{ printer.protocol }}</span>
                    <span v-if="printer.location" style="color: var(--text-ghost); font-size: 10px;">{{ printer.location }}</span>
                  </div>
                  <p v-if="printer.model && printer.model !== printer.name" style="color: var(--text-ghost); font-size: 10px; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    {{ printer.model.substring(0, 100) }}
                  </p>
                </div>
              </div>
              <div style="display: flex; align-items: center; flex-shrink: 0;">
                <span style="width: 6px; height: 6px; border-radius: 50; background: #22c55e;"></span>
              </div>
            </div>
          </div>

          <!-- Empty state -->
          <div v-if="localPrinters.length === 0 && networkPrinters.length === 0 && !printersLoading && !printersError"
            style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); padding: 24px 16px; text-align: center;">
            <span class="material-symbols-rounded" style="font-size: 32px; color: #3a3a42;">print</span>
            <p style="color: var(--text-dim); font-size: 12px; margin-top: 8px;">Press Scan Network to discover printers</p>
            <p style="color: var(--text-ghost); font-size: 11px; margin-top: 4px;">Scans installed printers and network devices on ports 9100 (JetDirect), 631 (IPP), 515 (LPR)</p>
            <p style="color: var(--text-ghost); font-size: 11px; margin-top: 2px;">Discovered printers are also available in Automation Hub workflows</p>
          </div>
        </div>

        <!-- App Lock settings -->
        <div style="margin-bottom: 32px;">
          <h3 style="color: var(--text-primary); font-size: 14px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span class="material-symbols-rounded" style="font-size: 18px; color: #22c55e;">screen_lock_portrait</span>
            App Lock
          </h3>
          <div style="background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border); overflow: hidden;">
            <!-- PIN status row -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border);">
              <div style="display: flex; align-items: center; gap: 12px;">
                <div 
                  :style="{ 
                    width: '36px', height: '36px', borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center',
                    background: hasPin ? 'rgba(34, 197, 94, 0.15)' : 'var(--bg-elevated)'
                  }"
                >
                  <span class="material-symbols-rounded" :style="{ fontSize: '20px', color: hasPin ? '#22c55e' : '#6b7280' }">
                    {{ hasPin ? 'pin' : 'pin_end' }}
                  </span>
                </div>
                <div>
                  <p :style="{ color: 'var(--text-primary)', fontSize: '13px' }">{{ hasPin ? 'PIN is set' : 'No PIN configured' }}</p>
                  <p style="color: var(--text-dim); font-size: 11px;">{{ hasPin ? 'App lock is available' : 'Set a PIN to enable app lock' }}</p>
                </div>
              </div>
              <div style="display: flex; gap: 8px;">
                <button
                  v-if="hasPin"
                  @click="showChangePin = true; pinError = ''; oldPinValue = ''; newPinChange = ''; confirmPinChange = ''"
                  style="padding: 6px 14px; border-radius: 8px; background: var(--bg-elevated); color: var(--text-primary); font-size: 12px;"
                  class="hover:bg-[--bg-elevated-hover] transition-colors"
                >
                  Change
                </button>
                <button
                  v-if="hasPin"
                  @click="removeLockPin"
                  style="padding: 6px 14px; border-radius: 8px; background: transparent; color: #ef4444; font-size: 12px; border: 1px solid #ef444440;"
                  class="hover:bg-[#ef444415] transition-colors"
                >
                  Remove
                </button>
                <button
                  v-else
                  @click="showSetPin = true; pinError = ''; newPinValue = ''; confirmPinValue = ''"
                  style="padding: 6px 14px; border-radius: 8px; background: #22c55e; color: var(--text-primary); font-size: 12px;"
                  class="hover:bg-[#15803d] transition-colors"
                >
                  Set PIN
                </button>
              </div>
            </div>
            
            <!-- Lock enabled toggle -->
            <div v-if="hasPin" style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border);">
              <div>
                <p style="color: var(--text-primary); font-size: 13px;">Enable app lock</p>
                <p style="color: var(--text-dim); font-size: 11px;">Lock the app when idle or minimized</p>
              </div>
              <button
                @click="toggleLockEnabled"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none',
                  lockEnabled ? 'bg-primary-500' : 'bg-surface-600'
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5',
                    lockEnabled ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
                  ]"
                />
              </button>
            </div>
            
            <!-- Lock timeout -->
            <div v-if="hasPin" style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border);">
              <div>
                <p style="color: var(--text-primary); font-size: 13px;">Auto-lock timeout</p>
                <p style="color: var(--text-dim); font-size: 11px;">Lock after this period of inactivity</p>
              </div>
              <select
                :value="lockTimeout"
                @change="updateLockTimeout(Number(($event.target as HTMLSelectElement).value))"
                :disabled="!lockEnabled"
                style="padding: 6px 10px; border-radius: 8px; background: var(--bg-deep); border: 1px solid var(--border); color: var(--text-primary); font-size: 12px; outline: none;"
                :style="{ opacity: lockEnabled ? '1' : '0.5' }"
              >
                <option :value="0">Manual only</option>
                <option :value="1">1 minute</option>
                <option :value="2">2 minutes</option>
                <option :value="5">5 minutes</option>
                <option :value="10">10 minutes</option>
                <option :value="15">15 minutes</option>
                <option :value="30">30 minutes</option>
              </select>
            </div>
            
            <!-- Lock on minimize -->
            <div v-if="hasPin" style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border);">
              <div>
                <p style="color: var(--text-primary); font-size: 13px;">Lock on minimize</p>
                <p style="color: var(--text-dim); font-size: 11px;">Also lock when the window is minimized</p>
              </div>
              <button
                @click="toggleLockOnMinimize"
                :disabled="!lockEnabled"
                :class="[
                  'relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors duration-200 ease-in-out focus:outline-none',
                  lockOnMinimize && lockEnabled ? 'bg-primary-500' : 'bg-surface-600',
                  !lockEnabled ? 'opacity-50' : ''
                ]"
              >
                <span
                  :class="[
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out mt-0.5',
                    lockOnMinimize && lockEnabled ? 'translate-x-5 ml-0.5' : 'translate-x-0.5'
                  ]"
                />
              </button>
            </div>
            
            <!-- Biometric info -->
            <div v-if="biometricAvailable" style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: rgba(59, 130, 246, 0.08); border-bottom: 1px solid var(--border);">
              <span class="material-symbols-rounded" style="font-size: 20px; color: #60a5fa;">fingerprint</span>
              <p style="color: #93c5fd; font-size: 12px;">Touch ID is available and will be offered on the lock screen.</p>
            </div>
            
            <!-- Lock now button -->
            <div v-if="hasPin && lockEnabled" style="padding: 14px 16px;">
              <button
                @click="lockNow"
                style="padding: 8px 16px; border-radius: 8px; background: var(--bg-elevated); color: var(--text-primary); font-size: 12px; display: flex; align-items: center; gap: 6px;"
                class="hover:bg-[--bg-elevated-hover] transition-colors"
              >
                <span class="material-symbols-rounded" style="font-size: 16px;">lock</span>
                Lock now
              </button>
            </div>
          </div>
        </div>
        
        <!-- Set PIN Modal -->
        <Teleport to="body">
          <div v-if="showSetPin" style="position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; background: var(--modal-overlay); backdrop-filter: blur(4px);">
            <div style="background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border); padding: 24px; max-width: 360px; width: 90%;" @click.stop>
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(34, 197, 94, 0.15); display: flex; align-items: center; justify-content: center;">
                  <span class="material-symbols-rounded" style="font-size: 22px; color: #22c55e;">pin</span>
                </div>
                <div>
                  <h3 style="color: var(--text-primary); font-size: 16px; font-weight: 600;">Set Lock PIN</h3>
                  <p style="color: var(--text-dim); font-size: 11px;">4-8 digit PIN to protect your app</p>
                </div>
              </div>
              
              <div style="display: flex; flex-direction: column; gap: 12px;">
                <div>
                  <label style="color: var(--text-muted); font-size: 11px; display: block; margin-bottom: 4px;">New PIN</label>
                  <input
                    v-model="newPinValue"
                    type="password"
                    inputmode="numeric"
                    maxlength="4"
                    placeholder="Enter 4-digit PIN"
                    style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg-deep); border: 1px solid var(--border); color: var(--text-primary); font-size: 13px; outline: none; letter-spacing: 4px;"
                  />
                </div>
                <div>
                  <label style="color: var(--text-muted); font-size: 11px; display: block; margin-bottom: 4px;">Confirm PIN</label>
                  <input
                    v-model="confirmPinValue"
                    type="password"
                    inputmode="numeric"
                    maxlength="4"
                    placeholder="Confirm PIN"
                    style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg-deep); border: 1px solid var(--border); color: var(--text-primary); font-size: 13px; outline: none; letter-spacing: 4px;"
                    @keyup.enter="handleSetPinSubmit"
                  />
                </div>
                <p v-if="pinError" style="color: #ef4444; font-size: 12px; display: flex; align-items: center; gap: 4px;">
                  <span class="material-symbols-rounded" style="font-size: 16px;">error</span>
                  {{ pinError }}
                </p>
              </div>
              
              <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px;">
                <button
                  @click="showSetPin = false"
                  style="padding: 8px 16px; border-radius: 8px; background: var(--bg-elevated); color: var(--text-primary); font-size: 13px;"
                  class="hover:bg-[--bg-elevated-hover] transition-colors"
                >
                  Cancel
                </button>
                <button
                  @click="handleSetPinSubmit"
                  :disabled="pinSaving"
                  style="padding: 8px 16px; border-radius: 8px; background: #22c55e; color: var(--text-primary); font-size: 13px; display: flex; align-items: center; gap: 6px;"
                  class="hover:bg-[#15803d] transition-colors disabled:opacity-50"
                >
                  <span v-if="pinSaving" class="material-symbols-rounded animate-spin" style="font-size: 16px;">sync</span>
                  Set PIN
                </button>
              </div>
            </div>
          </div>
        </Teleport>
        
        <!-- Change PIN Modal -->
        <Teleport to="body">
          <div v-if="showChangePin" style="position: fixed; inset: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; background: var(--modal-overlay); backdrop-filter: blur(4px);">
            <div style="background: var(--bg-card); border-radius: 16px; border: 1px solid var(--border); padding: 24px; max-width: 360px; width: 90%;" @click.stop>
              <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(245, 158, 11, 0.15); display: flex; align-items: center; justify-content: center;">
                  <span class="material-symbols-rounded" style="font-size: 22px; color: #f59e0b;">password</span>
                </div>
                <div>
                  <h3 style="color: var(--text-primary); font-size: 16px; font-weight: 600;">Change PIN</h3>
                  <p style="color: var(--text-dim); font-size: 11px;">Enter current PIN, then set a new one</p>
                </div>
              </div>
              
              <div style="display: flex; flex-direction: column; gap: 12px;">
                <div>
                  <label style="color: var(--text-muted); font-size: 11px; display: block; margin-bottom: 4px;">Current PIN</label>
                  <input
                    v-model="oldPinValue"
                    type="password"
                    inputmode="numeric"
                    maxlength="8"
                    placeholder="Current PIN"
                    style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg-deep); border: 1px solid var(--border); color: var(--text-primary); font-size: 13px; outline: none; letter-spacing: 4px;"
                  />
                </div>
                <div>
                  <label style="color: var(--text-muted); font-size: 11px; display: block; margin-bottom: 4px;">New PIN</label>
                  <input
                    v-model="newPinChange"
                    type="password"
                    inputmode="numeric"
                    maxlength="4"
                    placeholder="New 4-digit PIN"
                    style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg-deep); border: 1px solid var(--border); color: var(--text-primary); font-size: 13px; outline: none; letter-spacing: 4px;"
                  />
                </div>
                <div>
                  <label style="color: var(--text-muted); font-size: 11px; display: block; margin-bottom: 4px;">Confirm New PIN</label>
                  <input
                    v-model="confirmPinChange"
                    type="password"
                    inputmode="numeric"
                    maxlength="4"
                    placeholder="Confirm new PIN"
                    style="width: 100%; padding: 10px 12px; border-radius: 8px; background: var(--bg-deep); border: 1px solid var(--border); color: var(--text-primary); font-size: 13px; outline: none; letter-spacing: 4px;"
                    @keyup.enter="handleChangePinSubmit"
                  />
                </div>
                <p v-if="pinError" style="color: #ef4444; font-size: 12px; display: flex; align-items: center; gap: 4px;">
                  <span class="material-symbols-rounded" style="font-size: 16px;">error</span>
                  {{ pinError }}
                </p>
              </div>
              
              <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px;">
                <button
                  @click="showChangePin = false"
                  style="padding: 8px 16px; border-radius: 8px; background: var(--bg-elevated); color: var(--text-primary); font-size: 13px;"
                  class="hover:bg-[--bg-elevated-hover] transition-colors"
                >
                  Cancel
                </button>
                <button
                  @click="handleChangePinSubmit"
                  :disabled="pinSaving"
                  style="padding: 8px 16px; border-radius: 8px; background: #22c55e; color: var(--text-primary); font-size: 13px; display: flex; align-items: center; gap: 6px;"
                  class="hover:bg-[#15803d] transition-colors disabled:opacity-50"
                >
                  <span v-if="pinSaving" class="material-symbols-rounded animate-spin" style="font-size: 16px;">sync</span>
                  Change PIN
                </button>
              </div>
            </div>
          </div>
        </Teleport>
        
        <!-- Save button -->
        <div style="display: flex; align-items: center; gap: 12px;">
          <button
            @click="saveSettings"
            :disabled="isSaving"
            style="padding: 12px 24px; border-radius: 9999px; background: #22c55e; color: var(--text-primary); font-weight: 500; font-size: 14px; display: flex; align-items: center; gap: 8px;"
            class="hover:bg-[#15803d] transition-colors disabled:opacity-50"
          >
            <span v-if="isSaving" class="material-symbols-rounded animate-spin" style="font-size: 18px;">sync</span>
            <span v-else class="material-symbols-rounded" style="font-size: 18px;">save</span>
            {{ isSaving ? 'Saving...' : 'Save Settings' }}
          </button>
          <span v-if="saveMessage" :style="{ color: saveMessage.includes('Failed') ? '#ef4444' : '#22c55e', fontSize: '13px' }">
            {{ saveMessage }}
          </span>
        </div>
      </div>
    </div>

    <!-- Watch folder management (project info, change folder, remove) -->
    <WatchFolderManageModal :folder="managedWatchFolder" @close="managedWatchFolder = null" />
  </div>
</template>

