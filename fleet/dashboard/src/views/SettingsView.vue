<script setup>
import { ref, onMounted, computed } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import aiHelper from '../services/aiHelper'
import api from '../services/api'

const auth = useAuthStore()
const toast = useToastStore()

// AI Helper state
const aiSettings = ref({
  openai_api_key: '',
  openai_model: 'gpt-4o',
  max_tokens: 4000,
  temperature: 0.3,
  response_language: 'en',
  is_configured: false
})
const loadingAI = ref(false)
const savingAI = ref(false)
const showApiKey = ref(false)
const newApiKey = ref('')

// Available models
const availableModels = [
  { value: 'gpt-4o', label: 'GPT-4o (Recommended)' },
  { value: 'gpt-4o-mini', label: 'GPT-4o Mini (Faster, cheaper)' },
  { value: 'gpt-4-turbo', label: 'GPT-4 Turbo' },
  { value: 'gpt-4', label: 'GPT-4' },
  { value: 'gpt-3.5-turbo', label: 'GPT-3.5 Turbo (Cheapest)' },
]

// Load AI settings
const loadAISettings = async () => {
  loadingAI.value = true
  try {
    const settings = await aiHelper.getSettings()
    aiSettings.value = settings
  } catch (error) {
    console.error('Failed to load AI settings:', error)
  } finally {
    loadingAI.value = false
  }
}

// Save AI settings
const saveAISettings = async () => {
  savingAI.value = true
  try {
    const settingsToSave = {
      openai_model: aiSettings.value.openai_model,
      max_tokens: String(aiSettings.value.max_tokens),
      temperature: String(aiSettings.value.temperature),
      response_language: aiSettings.value.response_language,
    }
    
    // Only include API key if a new one was entered
    if (newApiKey.value) {
      settingsToSave.openai_api_key = newApiKey.value
    }
    
    await aiHelper.updateSettings(settingsToSave)
    toast.success('AI settings saved successfully')
    newApiKey.value = ''
    showApiKey.value = false
    await loadAISettings()
  } catch (error) {
    toast.error(error.message || 'Failed to save AI settings')
  } finally {
    savingAI.value = false
  }
}

// Fleet SSH management key state
const sshKeyStatus = ref({
  configured: false,
  source: 'none',
  has_passphrase: false,
  fingerprint: null,
  authorized_public_key: '',
})
const loadingSshKey = ref(false)
const savingSshKey = ref(false)
const newSshKey = ref('')
const newSshPassphrase = ref('')
const editingMgmtKey = ref(false)

// Secrets are never returned by the API (only configured / has_passphrase /
// fingerprint). When a key is stored we render a masked placeholder so the
// operator can SEE it persisted after saving instead of a blank box. "Replace
// key" switches back to an editable field to rotate it.
const MASKED_KEY = '****************************************\n****************************************\n****************************************'
const MASKED_PASSPHRASE = '****************'

const startEditMgmtKey = () => {
  editingMgmtKey.value = true
  newSshKey.value = ''
  newSshPassphrase.value = ''
}
const cancelEditMgmtKey = () => {
  editingMgmtKey.value = false
  newSshKey.value = ''
  newSshPassphrase.value = ''
}

// Fleet-wide SSH login defaults (one place for all servers).
const sshDefaults = ref({ default_local_key_path: '', fleet_ignore_ips: '' })
const savingSshDefaults = ref(false)

const loadSshKey = async () => {
  loadingSshKey.value = true
  try {
    const res = await api.get('/api/settings/ssh')
    sshKeyStatus.value = res.data || sshKeyStatus.value
    sshDefaults.value = {
      default_local_key_path: res.data?.default_local_key_path || '',
      fleet_ignore_ips: res.data?.fleet_ignore_ips || '',
    }
  } catch (error) {
    console.error('Failed to load SSH key status:', error)
  } finally {
    loadingSshKey.value = false
  }
}

const saveSshDefaults = async () => {
  savingSshDefaults.value = true
  try {
    const res = await api.put('/api/settings/ssh-defaults', {
      default_local_key_path: sshDefaults.value.default_local_key_path,
      fleet_ignore_ips: sshDefaults.value.fleet_ignore_ips,
    })
    sshDefaults.value = {
      default_local_key_path: res.data?.default_local_key_path ?? sshDefaults.value.default_local_key_path,
      fleet_ignore_ips: res.data?.fleet_ignore_ips ?? sshDefaults.value.fleet_ignore_ips,
    }
    toast.success(res.data?.message || 'SSH defaults saved')
  } catch (error) {
    toast.error(error.response?.data?.error || error.message || 'Failed to save SSH defaults')
  } finally {
    savingSshDefaults.value = false
  }
}

const saveSshKey = async () => {
  if (!newSshKey.value.trim()) {
    toast.error('Paste a private key first')
    return
  }
  savingSshKey.value = true
  try {
    const res = await api.put('/api/settings/ssh', {
      private_key: newSshKey.value,
      passphrase: newSshPassphrase.value,
    })
    toast.success(res.data?.message || 'Management key saved')
    newSshKey.value = ''
    newSshPassphrase.value = ''
    editingMgmtKey.value = false
    await loadSshKey()
  } catch (error) {
    toast.error(error.response?.data?.error || error.message || 'Failed to save key')
  } finally {
    savingSshKey.value = false
  }
}

const clearSshKey = async () => {
  if (!confirm('Remove the stored management key? The Fleet Manager will fall back to its config-file key (if any) and per-server keys.')) {
    return
  }
  savingSshKey.value = true
  try {
    await api.put('/api/settings/ssh', { private_key: '', passphrase: '' })
    toast.success('Management key cleared')
    newSshKey.value = ''
    newSshPassphrase.value = ''
    await loadSshKey()
  } catch (error) {
    toast.error(error.response?.data?.error || error.message || 'Failed to clear key')
  } finally {
    savingSshKey.value = false
  }
}

const copyAuthorizedKey = () => {
  if (!sshKeyStatus.value.authorized_public_key) return
  navigator.clipboard.writeText(sshKeyStatus.value.authorized_public_key)
  toast.success('Public key copied')
}

// Tab state
const activeTab = ref('security')

// Loading states
const loading = ref({
  twoFactor: false,
  devices: false,
  sessions: false,
  enable2FA: false,
  confirm2FA: false,
  disable2FA: false,
  regenerateCodes: false
})

// 2FA state
const twoFactorStatus = ref({
  enabled: false,
  backup_codes_remaining: 0
})
const setupData = ref(null)
const totpCode = ref('')
const disablePassword = ref('')
const regeneratePassword = ref('')

// Trusted devices
const trustedDevices = ref([])

// Sessions
const sessions = ref([])

// Modals
const showSetup2FAModal = ref(false)
const showDisable2FAModal = ref(false)
const showBackupCodesModal = ref(false)
const showRegenerateCodesModal = ref(false)
const backupCodes = ref([])

// Computed
const is2FAEnabled = computed(() => auth.user?.totp_enabled || twoFactorStatus.value.enabled)

// Methods
const load2FAStatus = async () => {
  loading.value.twoFactor = true
  try {
    const data = await auth.get2FAStatus()
    twoFactorStatus.value = data
  } catch (error) {
    // User may not have 2FA set up yet
  } finally {
    loading.value.twoFactor = false
  }
}

const loadTrustedDevices = async () => {
  loading.value.devices = true
  try {
    const data = await auth.getTrustedDevices()
    trustedDevices.value = data.devices || []
  } catch (error) {
    console.error('Failed to load trusted devices:', error)
  } finally {
    loading.value.devices = false
  }
}

const loadSessions = async () => {
  loading.value.sessions = true
  try {
    const data = await auth.getSessions()
    sessions.value = data.sessions || []
  } catch (error) {
    console.error('Failed to load sessions:', error)
  } finally {
    loading.value.sessions = false
  }
}

const startEnable2FA = async () => {
  loading.value.enable2FA = true
  try {
    const data = await auth.enable2FA()
    setupData.value = data
    showSetup2FAModal.value = true
  } catch (error) {
    toast.error(error.message || 'Failed to start 2FA setup')
  } finally {
    loading.value.enable2FA = false
  }
}

const confirm2FA = async () => {
  if (!totpCode.value || totpCode.value.length !== 6) {
    toast.error('Please enter a valid 6-digit code')
    return
  }

  loading.value.confirm2FA = true
  try {
    const data = await auth.confirm2FA(totpCode.value)
    backupCodes.value = data.backup_codes || []
    showSetup2FAModal.value = false
    showBackupCodesModal.value = true
    twoFactorStatus.value.enabled = true
    totpCode.value = ''
    setupData.value = null
    toast.success('Two-factor authentication enabled!')
  } catch (error) {
    toast.error(error.message || 'Invalid verification code')
  } finally {
    loading.value.confirm2FA = false
  }
}

const disable2FA = async () => {
  if (!disablePassword.value) {
    toast.error('Please enter your password')
    return
  }

  loading.value.disable2FA = true
  try {
    await auth.disable2FA(disablePassword.value)
    showDisable2FAModal.value = false
    twoFactorStatus.value.enabled = false
    disablePassword.value = ''
    toast.success('Two-factor authentication disabled')
    await load2FAStatus()
  } catch (error) {
    toast.error(error.message || 'Invalid password')
  } finally {
    loading.value.disable2FA = false
  }
}

const regenerateBackupCodes = async () => {
  if (!regeneratePassword.value) {
    toast.error('Please enter your password')
    return
  }

  loading.value.regenerateCodes = true
  try {
    const data = await auth.regenerateBackupCodes(regeneratePassword.value)
    backupCodes.value = data.backup_codes || []
    showRegenerateCodesModal.value = false
    showBackupCodesModal.value = true
    regeneratePassword.value = ''
    toast.success('Backup codes regenerated!')
    await load2FAStatus()
  } catch (error) {
    toast.error(error.message || 'Invalid password')
  } finally {
    loading.value.regenerateCodes = false
  }
}

const removeTrustedDevice = async (deviceId) => {
  try {
    await auth.removeTrustedDevice(deviceId)
    trustedDevices.value = trustedDevices.value.filter(d => d.id !== deviceId)
    toast.success('Device removed')
  } catch (error) {
    toast.error(error.message || 'Failed to remove device')
  }
}

const revokeSession = async (sessionId) => {
  try {
    await auth.revokeSession(sessionId)
    sessions.value = sessions.value.filter(s => s.id !== sessionId)
    toast.success('Session revoked')
  } catch (error) {
    toast.error(error.message || 'Failed to revoke session')
  }
}

const revokeAllOtherSessions = async () => {
  try {
    await auth.revokeAllOtherSessions()
    await loadSessions()
    toast.success('All other sessions revoked')
  } catch (error) {
    toast.error(error.message || 'Failed to revoke sessions')
  }
}

const copyBackupCodes = () => {
  const text = backupCodes.value.join('\n')
  navigator.clipboard.writeText(text)
  toast.success('Backup codes copied to clipboard')
}

const formatDate = (dateStr) => {
  if (!dateStr) return 'Never'
  const date = new Date(dateStr)
  return date.toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const getDeviceIcon = (device) => {
  const ua = (device.user_agent || device.browser || '').toLowerCase()
  if (ua.includes('mobile') || ua.includes('android') || ua.includes('iphone')) return 'smartphone'
  if (ua.includes('tablet') || ua.includes('ipad')) return 'tablet'
  return 'computer'
}

const getOSIcon = (os) => {
  if (!os) return 'devices'
  const osLower = os.toLowerCase()
  if (osLower.includes('windows')) return 'desktop_windows'
  if (osLower.includes('mac') || osLower.includes('ios')) return 'laptop_mac'
  if (osLower.includes('linux')) return 'terminal'
  if (osLower.includes('android')) return 'android'
  return 'devices'
}

// Load data on mount
onMounted(() => {
  load2FAStatus()
  loadTrustedDevices()
  loadSessions()
  loadAISettings()
  loadSshKey()
})
</script>

<template>
  <div class="animate-fadeIn">
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold">Settings</h1>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 mb-6 p-1 bg-surface-100 dark:bg-surface-800/50 rounded-xl w-fit">
      <button
        @click="activeTab = 'security'"
        :class="[
          'px-4 py-2 rounded-lg text-sm font-medium transition-all',
          activeTab === 'security' 
            ? 'bg-white dark:bg-surface-700 shadow-sm text-surface-900 dark:text-surface-100' 
            : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
        ]"
      >
        <span class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg">security</span>
          Security
        </span>
      </button>
      <button
        @click="activeTab = 'sessions'"
        :class="[
          'px-4 py-2 rounded-lg text-sm font-medium transition-all',
          activeTab === 'sessions' 
            ? 'bg-white dark:bg-surface-700 shadow-sm text-surface-900 dark:text-surface-100' 
            : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
        ]"
      >
        <span class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg">devices</span>
          Sessions
        </span>
      </button>
      <button
        @click="activeTab = 'ai'"
        :class="[
          'px-4 py-2 rounded-lg text-sm font-medium transition-all',
          activeTab === 'ai' 
            ? 'bg-white dark:bg-surface-700 shadow-sm text-surface-900 dark:text-surface-100' 
            : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
        ]"
      >
        <span class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg">psychology</span>
          AI Helper
        </span>
      </button>
      <button
        @click="activeTab = 'fleet-ssh'"
        :class="[
          'px-4 py-2 rounded-lg text-sm font-medium transition-all',
          activeTab === 'fleet-ssh' 
            ? 'bg-white dark:bg-surface-700 shadow-sm text-surface-900 dark:text-surface-100' 
            : 'text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
        ]"
      >
        <span class="flex items-center gap-2">
          <span class="material-symbols-rounded text-lg">vpn_key</span>
          Fleet Access
        </span>
      </button>
    </div>

    <!-- Security Tab -->
    <div v-if="activeTab === 'security'" class="space-y-6">
      <!-- Two-Factor Authentication -->
      <div class="card">
        <div class="card-header">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-primary-500/10 flex items-center justify-center">
              <span class="material-symbols-rounded text-primary-500">verified_user</span>
            </div>
            <div>
              <h2 class="text-lg font-semibold">Two-Factor Authentication</h2>
              <p class="text-sm text-muted">Add an extra layer of security to your account</p>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div v-if="loading.twoFactor" class="flex items-center justify-center py-8">
            <span class="spinner text-primary-500"></span>
          </div>
          <div v-else>
            <!-- 2FA Status -->
            <div class="flex items-center justify-between p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50">
              <div class="flex items-center gap-3">
                <div :class="[
                  'w-3 h-3 rounded-full',
                  is2FAEnabled ? 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.5)]' : 'bg-surface-400'
                ]"></div>
                <div>
                  <p class="font-medium">{{ is2FAEnabled ? 'Enabled' : 'Disabled' }}</p>
                  <p v-if="is2FAEnabled && twoFactorStatus.backup_codes_remaining !== undefined" class="text-sm text-muted">
                    {{ twoFactorStatus.backup_codes_remaining }} backup codes remaining
                  </p>
                </div>
              </div>
              <div class="flex gap-2">
                <template v-if="is2FAEnabled">
                  <button 
                    @click="showRegenerateCodesModal = true"
                    class="btn-secondary btn-sm"
                  >
                    <span class="material-symbols-rounded text-lg">key</span>
                    Regenerate Codes
                  </button>
                  <button 
                    @click="showDisable2FAModal = true"
                    class="btn-danger btn-sm"
                  >
                    <span class="material-symbols-rounded text-lg">block</span>
                    Disable
                  </button>
                </template>
                <button 
                  v-else
                  @click="startEnable2FA"
                  class="btn-primary btn-sm"
                  :disabled="loading.enable2FA"
                >
                  <span v-if="loading.enable2FA" class="spinner-sm"></span>
                  <span v-else class="material-symbols-rounded text-lg">add</span>
                  Enable 2FA
                </button>
              </div>
            </div>

            <p class="text-sm text-muted mt-4">
              Two-factor authentication adds an additional layer of security by requiring a verification 
              code from your authenticator app when signing in.
            </p>
          </div>
        </div>
      </div>

      <!-- Trusted Devices -->
      <div class="card">
        <div class="card-header">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-500/10 flex items-center justify-center">
              <span class="material-symbols-rounded text-blue-500">devices</span>
            </div>
            <div>
              <h2 class="text-lg font-semibold">Trusted Devices</h2>
              <p class="text-sm text-muted">Devices that can skip 2FA verification</p>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div v-if="loading.devices" class="flex items-center justify-center py-8">
            <span class="spinner text-primary-500"></span>
          </div>
          <div v-else-if="trustedDevices.length === 0" class="text-center py-8">
            <span class="material-symbols-rounded text-4xl text-surface-400 mb-2">devices</span>
            <p class="text-muted">No trusted devices</p>
            <p class="text-sm text-surface-400 mt-1">
              When you check "Trust this device" during 2FA verification, it will appear here
            </p>
          </div>
          <div v-else class="space-y-3">
            <div
              v-for="device in trustedDevices"
              :key="device.id"
              class="flex items-center justify-between p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50"
            >
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-surface-200 dark:bg-surface-700 flex items-center justify-center">
                  <span class="material-symbols-rounded text-surface-600 dark:text-surface-300">{{ getDeviceIcon(device) }}</span>
                </div>
                <div>
                  <p class="font-medium">{{ device.device_name || 'Unknown Device' }}</p>
                  <p class="text-sm text-muted">
                    Trusted on {{ formatDate(device.trusted_at || device.created_at) }}
                    <template v-if="device.expires_at"> &bull; Expires {{ formatDate(device.expires_at) }}</template>
                  </p>
                </div>
              </div>
              <button
                @click="removeTrustedDevice(device.id)"
                class="btn-ghost btn-sm text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10"
              >
                <span class="material-symbols-rounded text-lg">delete</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Sessions Tab -->
    <div v-if="activeTab === 'sessions'" class="space-y-6">
      <div class="card">
        <div class="card-header flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center">
              <span class="material-symbols-rounded text-amber-500">history</span>
            </div>
            <div>
              <h2 class="text-lg font-semibold">Active Sessions</h2>
              <p class="text-sm text-muted">Manage your active login sessions</p>
            </div>
          </div>
          <button
            v-if="sessions.length > 1"
            @click="revokeAllOtherSessions"
            class="btn-danger btn-sm"
          >
            <span class="material-symbols-rounded text-lg">logout</span>
            Revoke All Other
          </button>
        </div>
        <div class="card-body">
          <div v-if="loading.sessions" class="flex items-center justify-center py-8">
            <span class="spinner text-primary-500"></span>
          </div>
          <div v-else-if="sessions.length === 0" class="text-center py-8">
            <span class="material-symbols-rounded text-4xl text-surface-400 mb-2">devices</span>
            <p class="text-muted">No active sessions</p>
          </div>
          <div v-else class="space-y-3">
            <div
              v-for="session in sessions"
              :key="session.id"
              :class="[
                'flex items-center justify-between p-4 rounded-xl',
                session.is_current ? 'bg-primary-50 dark:bg-primary-500/10 border border-primary-200 dark:border-primary-500/30' : 'bg-surface-50 dark:bg-surface-800/50'
              ]"
            >
              <div class="flex items-center gap-3">
                <div :class="[
                  'w-10 h-10 rounded-xl flex items-center justify-center',
                  session.is_current ? 'bg-primary-500/20' : 'bg-surface-200 dark:bg-surface-700'
                ]">
                  <span :class="[
                    'material-symbols-rounded',
                    session.is_current ? 'text-primary-500' : 'text-surface-600 dark:text-surface-300'
                  ]">{{ getOSIcon(session.os) }}</span>
                </div>
                <div>
                  <div class="flex items-center gap-2">
                    <p class="font-medium">{{ session.browser || 'Unknown Browser' }} on {{ session.os || 'Unknown OS' }}</p>
                    <span v-if="session.is_current" class="badge badge-success text-xs">Current</span>
                  </div>
                  <p class="text-sm text-muted">
                    {{ session.ip_address || 'Unknown IP' }}
                    &bull; Last active {{ formatDate(session.last_active_at || session.created_at) }}
                  </p>
                  <p v-if="session.device_name" class="text-xs text-surface-400 mt-0.5">
                    {{ session.device_name }}
                  </p>
                </div>
              </div>
              <button
                v-if="!session.is_current"
                @click="revokeSession(session.id)"
                class="btn-ghost btn-sm text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10"
              >
                <span class="material-symbols-rounded text-lg">logout</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- AI Helper Tab -->
    <div v-if="activeTab === 'ai'" class="space-y-6">
      <!-- OpenAI Configuration -->
      <div class="card">
        <div class="card-header">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-purple-500 to-indigo-500 flex items-center justify-center">
              <span class="material-symbols-rounded text-white">psychology</span>
            </div>
            <div>
              <h2 class="text-lg font-semibold">AI Assistant Configuration</h2>
              <p class="text-sm text-muted">Configure OpenAI API for config analysis and log insights</p>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div v-if="loadingAI" class="flex items-center justify-center py-8">
            <span class="spinner text-primary-500"></span>
          </div>
          <div v-else class="space-y-6">
            <!-- Status Badge -->
            <div class="flex items-center justify-between p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50">
              <div class="flex items-center gap-3">
                <div :class="[
                  'w-3 h-3 rounded-full',
                  aiSettings.is_configured ? 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.5)]' : 'bg-amber-500'
                ]"></div>
                <div>
                  <p class="font-medium">{{ aiSettings.is_configured ? 'API Key Configured' : 'Not Configured' }}</p>
                  <p v-if="aiSettings.is_configured" class="text-sm text-muted">
                    Key ending in {{ aiSettings.openai_api_key }}
                  </p>
                  <p v-else class="text-sm text-muted">
                    Enter your OpenAI API key to enable AI features
                  </p>
                </div>
              </div>
            </div>

            <!-- API Key -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                OpenAI API Key
              </label>
              <div class="flex gap-2">
                <div class="relative flex-1">
                  <input
                    v-model="newApiKey"
                    :type="showApiKey ? 'text' : 'password'"
                    class="input w-full pr-10 font-mono"
                    :placeholder="aiSettings.is_configured ? 'Enter new key to update...' : 'sk-...'"
                  />
                  <button 
                    @click="showApiKey = !showApiKey"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-surface-400 hover:text-surface-600"
                    type="button"
                  >
                    <span class="material-symbols-rounded text-lg">{{ showApiKey ? 'visibility_off' : 'visibility' }}</span>
                  </button>
                </div>
              </div>
              <p class="text-xs text-surface-500 mt-2">
                Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank" class="text-primary-500 hover:underline">OpenAI Platform</a>
              </p>
            </div>

            <!-- Model Selection -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                AI Model
              </label>
              <select v-model="aiSettings.openai_model" class="input w-full">
                <option v-for="model in availableModels" :key="model.value" :value="model.value">
                  {{ model.label }}
                </option>
              </select>
              <p class="text-xs text-surface-500 mt-2">
                GPT-4o is recommended for best results. GPT-3.5 Turbo is faster and cheaper for basic tasks.
              </p>
            </div>

            <!-- Advanced Settings -->
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  Max Tokens
                </label>
                <input
                  v-model.number="aiSettings.max_tokens"
                  type="number"
                  min="500"
                  max="8000"
                  step="500"
                  class="input w-full"
                />
                <p class="text-xs text-surface-500 mt-1">Maximum response length (500-8000)</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                  Temperature
                </label>
                <input
                  v-model.number="aiSettings.temperature"
                  type="number"
                  min="0"
                  max="1"
                  step="0.1"
                  class="input w-full"
                />
                <p class="text-xs text-surface-500 mt-1">Creativity (0 = precise, 1 = creative)</p>
              </div>
            </div>

            <!-- Response Language -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Response Language
              </label>
              <select v-model="aiSettings.response_language" class="input w-full">
                <option value="en">English</option>
                <option value="hu">Magyar (Hungarian)</option>
                <option value="de">Deutsch (German)</option>
                <option value="es">Espanol (Spanish)</option>
                <option value="fr">Francais (French)</option>
              </select>
            </div>

            <!-- Save Button -->
            <div class="flex justify-end pt-4 border-t border-surface-200 dark:border-surface-700">
              <button 
                @click="saveAISettings" 
                :disabled="savingAI"
                class="btn-primary"
              >
                <span v-if="savingAI" class="spinner-sm"></span>
                <span v-else class="material-symbols-rounded text-lg">save</span>
                Save AI Settings
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Usage Info -->
      <div class="card">
        <div class="card-header">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-500/10 flex items-center justify-center">
              <span class="material-symbols-rounded text-blue-500">info</span>
            </div>
            <div>
              <h2 class="text-lg font-semibold">AI Features</h2>
              <p class="text-sm text-muted">What you can do with the AI Assistant</p>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex items-start gap-3 p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50">
              <span class="material-symbols-rounded text-primary-500 mt-0.5">code</span>
              <div>
                <p class="font-medium">Config Analysis</p>
                <p class="text-sm text-muted">Get AI-powered analysis of server configuration files, security issues, and optimization suggestions.</p>
              </div>
            </div>
            <div class="flex items-start gap-3 p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50">
              <span class="material-symbols-rounded text-primary-500 mt-0.5">article</span>
              <div>
                <p class="font-medium">Log Analysis</p>
                <p class="text-sm text-muted">Analyze server logs to identify errors, security threats, and performance issues.</p>
              </div>
            </div>
            <div class="flex items-start gap-3 p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50">
              <span class="material-symbols-rounded text-primary-500 mt-0.5">build</span>
              <div>
                <p class="font-medium">Fix Suggestions</p>
                <p class="text-sm text-muted">Get exact commands and configuration changes to fix identified issues.</p>
              </div>
            </div>
            <div class="flex items-start gap-3 p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50">
              <span class="material-symbols-rounded text-primary-500 mt-0.5">open_in_full</span>
              <div>
                <p class="font-medium">Zen Mode Editor</p>
                <p class="text-sm text-muted">Full-screen config editor with AI panel - right-click to ask about selected text.</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Fleet Access (SSH) Tab -->
    <div v-if="activeTab === 'fleet-ssh'" class="space-y-6">
      <!-- Management Key -->
      <div class="card">
        <div class="card-header">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-500 flex items-center justify-center">
              <span class="material-symbols-rounded text-white">vpn_key</span>
            </div>
            <div>
              <h2 class="text-lg font-semibold">Fleet Management SSH Key</h2>
              <p class="text-sm text-muted">The private key the Fleet Manager uses to reach hardened (pxr) servers</p>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div v-if="loadingSshKey" class="flex items-center justify-center py-8">
            <span class="spinner text-primary-500"></span>
          </div>
          <div v-else class="space-y-6">
            <!-- Status -->
            <div class="flex items-center justify-between p-4 rounded-xl bg-surface-50 dark:bg-surface-800/50">
              <div class="flex items-center gap-3">
                <div :class="[
                  'w-3 h-3 rounded-full',
                  sshKeyStatus.configured ? 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.5)]' : 'bg-amber-500'
                ]"></div>
                <div>
                  <p class="font-medium">
                    {{ sshKeyStatus.configured ? 'Management key configured' : 'No management key set' }}
                    <span v-if="sshKeyStatus.configured && sshKeyStatus.source === 'config'" class="badge text-xs ml-1">from config file</span>
                    <span v-else-if="sshKeyStatus.configured" class="badge badge-success text-xs ml-1">stored in panel</span>
                  </p>
                  <p v-if="sshKeyStatus.fingerprint" class="text-sm text-muted font-mono">{{ sshKeyStatus.fingerprint }}</p>
                  <p v-else class="text-sm text-muted">
                    Paste the private half of the key you authorized on your servers (e.g. <span class="font-mono">vps-sftp-access</span>).
                  </p>
                </div>
              </div>
              <button
                v-if="sshKeyStatus.configured && sshKeyStatus.source === 'database'"
                @click="clearSshKey"
                class="btn-ghost btn-sm text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-500/10"
                :disabled="savingSshKey"
              >
                <span class="material-symbols-rounded text-lg">delete</span>
                Remove
              </button>
            </div>

            <!-- Private key -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                  Private key (PEM / OpenSSH)
                </label>
                <button
                  v-if="sshKeyStatus.configured && !editingMgmtKey"
                  @click="startEditMgmtKey"
                  class="btn-ghost btn-sm"
                >
                  <span class="material-symbols-rounded text-base">edit</span>
                  Replace key
                </button>
              </div>
              <!-- Masked, read-only view when a key is stored and we're not rotating it -->
              <textarea
                v-if="sshKeyStatus.configured && !editingMgmtKey"
                :value="MASKED_KEY"
                rows="4"
                readonly
                class="input w-full font-mono text-xs opacity-60 cursor-not-allowed select-none"
              ></textarea>
              <!-- Editable when no key yet, or when replacing -->
              <textarea
                v-else
                v-model="newSshKey"
                rows="8"
                class="input w-full font-mono text-xs"
                placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;...&#10;-----END OPENSSH PRIVATE KEY-----"
                spellcheck="false"
              ></textarea>
              <p class="text-xs text-surface-500 mt-2">
                <template v-if="sshKeyStatus.configured && !editingMgmtKey">
                  Stored encrypted in the panel database and masked here for safety. Click <strong>Replace key</strong> to rotate it.
                </template>
                <template v-else>
                  Stored encrypted in the panel database. It is masked after saving - to rotate, just paste a new key.
                </template>
              </p>
            </div>

            <!-- Passphrase -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Passphrase <span class="text-surface-400 font-normal">(optional)</span>
              </label>
              <!-- Masked, read-only when a key is stored and we're not rotating it -->
              <input
                v-if="sshKeyStatus.configured && !editingMgmtKey"
                :value="sshKeyStatus.has_passphrase ? MASKED_PASSPHRASE : ''"
                type="text"
                readonly
                class="input w-full font-mono opacity-60 cursor-not-allowed select-none"
                :placeholder="sshKeyStatus.has_passphrase ? '' : 'No passphrase set'"
              />
              <input
                v-else
                v-model="newSshPassphrase"
                type="password"
                class="input w-full font-mono"
                placeholder="Leave empty if the key has no passphrase"
                autocomplete="new-password"
              />
            </div>

            <!-- Save -->
            <div
              v-if="!sshKeyStatus.configured || editingMgmtKey"
              class="flex justify-end gap-2 pt-4 border-t border-surface-200 dark:border-surface-700"
            >
              <button
                v-if="editingMgmtKey"
                @click="cancelEditMgmtKey"
                :disabled="savingSshKey"
                class="btn-ghost"
              >
                Cancel
              </button>
              <button @click="saveSshKey" :disabled="savingSshKey" class="btn-primary">
                <span v-if="savingSshKey" class="spinner-sm"></span>
                <span v-else class="material-symbols-rounded text-lg">save</span>
                Save Management Key
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Default SSH login (applies to every server) -->
      <div class="card">
        <div class="card-header">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-sky-500 to-blue-500 flex items-center justify-center">
              <span class="material-symbols-rounded text-white">tune</span>
            </div>
            <div>
              <h2 class="text-lg font-semibold">Default SSH login</h2>
              <p class="text-sm text-muted">One place for all servers. Each server can still override these.</p>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div v-if="loadingSshKey" class="flex items-center justify-center py-8">
            <span class="spinner text-primary-500"></span>
          </div>
          <div v-else class="space-y-6">
            <!-- Default local key path -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Default private key path <span class="text-surface-400 font-normal">(on your computer)</span>
              </label>
              <input
                v-model="sshDefaults.default_local_key_path"
                type="text"
                class="input w-full font-mono"
                placeholder="e.g. D:\04 Work\ssh_keys\vps\vps_sftp_key"
                spellcheck="false"
                autocomplete="off"
              />
              <p class="text-xs text-surface-500 mt-2">
                Dropped into the copy-paste <span class="font-mono">ssh -i ...</span> command on every server page. Set it once here; override per-server in that server's SSH card when a box needs a different key.
              </p>
            </div>

            <!-- Fleet Manager IPs to whitelist -->
            <div>
              <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
                Fleet Manager IP(s) <span class="text-surface-400 font-normal">(optional)</span>
              </label>
              <input
                v-model="sshDefaults.fleet_ignore_ips"
                type="text"
                class="input w-full font-mono"
                placeholder="e.g. 203.0.113.10 198.51.100.0/24"
                spellcheck="false"
                autocomplete="off"
              />
              <p class="text-xs text-surface-500 mt-2">
                Added to each deployed server's <span class="font-mono">fail2ban</span> allow-list so the panel can never ban itself off a box it manages. The deploy also auto-detects the IP it connects from; set this only if the panel is behind NAT or has multiple egress IPs.
              </p>
            </div>

            <!-- Save -->
            <div class="flex justify-end pt-4 border-t border-surface-200 dark:border-surface-700">
              <button @click="saveSshDefaults" :disabled="savingSshDefaults" class="btn-primary">
                <span v-if="savingSshDefaults" class="spinner-sm"></span>
                <span v-else class="material-symbols-rounded text-lg">save</span>
                Save Defaults
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Authorized public key reference -->
      <div v-if="sshKeyStatus.authorized_public_key" class="card">
        <div class="card-header">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-500/10 flex items-center justify-center">
              <span class="material-symbols-rounded text-blue-500">key</span>
            </div>
            <div>
              <h2 class="text-lg font-semibold">Authorized Public Key</h2>
              <p class="text-sm text-muted">This public key is installed on every deployed server for the <span class="font-mono">pxr</span> user</p>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div class="flex items-start gap-2 p-3 bg-surface-100 dark:bg-surface-800 rounded-xl font-mono text-xs break-all">
            <span class="flex-1">{{ sshKeyStatus.authorized_public_key }}</span>
            <button @click="copyAuthorizedKey" class="btn-ghost btn-sm shrink-0">
              <span class="material-symbols-rounded text-lg">content_copy</span>
            </button>
          </div>
          <p class="text-xs text-surface-500 mt-2">
            The private key you paste above must be the matching half of this public key, otherwise the Fleet Manager will be locked out after hardening.
          </p>
        </div>
      </div>
    </div>

    <!-- Setup 2FA Modal -->
    <div v-if="showSetup2FAModal" class="modal-overlay" @click.self="showSetup2FAModal = false">
      <div class="modal">
        <div class="modal-header">
          <h3>Set Up Two-Factor Authentication</h3>
          <button @click="showSetup2FAModal = false" class="btn-close">
            <span class="material-symbols-rounded text-xl">close</span>
          </button>
        </div>
        <div class="modal-body space-y-6">
          <div class="text-center">
            <p class="text-muted mb-4">Scan this QR code with your authenticator app</p>
            <div v-if="setupData?.qr_code" class="inline-block p-4 bg-white rounded-xl">
              <img :src="setupData.qr_code" alt="QR Code" class="w-48 h-48" />
            </div>
          </div>

          <div>
            <p class="text-sm text-muted mb-2">Or enter this code manually:</p>
            <div class="flex items-center gap-2 p-3 bg-surface-100 dark:bg-surface-800 rounded-xl font-mono text-sm break-all">
              {{ setupData?.secret }}
              <button
                @click="navigator.clipboard.writeText(setupData?.secret); toast.success('Secret copied!')"
                class="btn-ghost btn-sm ml-auto shrink-0"
              >
                <span class="material-symbols-rounded text-lg">content_copy</span>
              </button>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium mb-2">Enter verification code</label>
            <input
              v-model="totpCode"
              type="text"
              class="input text-center text-2xl tracking-widest font-mono"
              placeholder="000000"
              maxlength="6"
              @keyup.enter="confirm2FA"
            />
          </div>
        </div>
        <div class="modal-footer">
          <button @click="showSetup2FAModal = false" class="btn-secondary">Cancel</button>
          <button @click="confirm2FA" class="btn-primary" :disabled="loading.confirm2FA">
            <span v-if="loading.confirm2FA" class="spinner-sm"></span>
            <span v-else>Enable 2FA</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Disable 2FA Modal -->
    <div v-if="showDisable2FAModal" class="modal-overlay" @click.self="showDisable2FAModal = false">
      <div class="modal">
        <div class="modal-header">
          <h3>Disable Two-Factor Authentication</h3>
          <button @click="showDisable2FAModal = false" class="btn-close">
            <span class="material-symbols-rounded text-xl">close</span>
          </button>
        </div>
        <div class="modal-body space-y-4">
          <div class="p-4 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-xl">
            <div class="flex items-start gap-3">
              <span class="material-symbols-rounded text-amber-500 mt-0.5">warning</span>
              <div>
                <p class="font-medium text-amber-700 dark:text-amber-400">This will reduce your account security</p>
                <p class="text-sm text-amber-600 dark:text-amber-300 mt-1">
                  Disabling two-factor authentication means anyone with your password can access your account.
                </p>
              </div>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium mb-2">Enter your password to confirm</label>
            <input
              v-model="disablePassword"
              type="password"
              class="input"
              placeholder="Enter your password"
              @keyup.enter="disable2FA"
            />
          </div>
        </div>
        <div class="modal-footer">
          <button @click="showDisable2FAModal = false" class="btn-secondary">Cancel</button>
          <button @click="disable2FA" class="btn-danger" :disabled="loading.disable2FA">
            <span v-if="loading.disable2FA" class="spinner-sm"></span>
            <span v-else>Disable 2FA</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Regenerate Codes Modal -->
    <div v-if="showRegenerateCodesModal" class="modal-overlay" @click.self="showRegenerateCodesModal = false">
      <div class="modal">
        <div class="modal-header">
          <h3>Regenerate Backup Codes</h3>
          <button @click="showRegenerateCodesModal = false" class="btn-close">
            <span class="material-symbols-rounded text-xl">close</span>
          </button>
        </div>
        <div class="modal-body space-y-4">
          <p class="text-muted">
            This will invalidate all existing backup codes and generate new ones.
            Make sure to save the new codes in a secure location.
          </p>

          <div>
            <label class="block text-sm font-medium mb-2">Enter your password to confirm</label>
            <input
              v-model="regeneratePassword"
              type="password"
              class="input"
              placeholder="Enter your password"
              @keyup.enter="regenerateBackupCodes"
            />
          </div>
        </div>
        <div class="modal-footer">
          <button @click="showRegenerateCodesModal = false" class="btn-secondary">Cancel</button>
          <button @click="regenerateBackupCodes" class="btn-primary" :disabled="loading.regenerateCodes">
            <span v-if="loading.regenerateCodes" class="spinner-sm"></span>
            <span v-else>Regenerate</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Backup Codes Modal -->
    <div v-if="showBackupCodesModal" class="modal-overlay" @click.self="showBackupCodesModal = false">
      <div class="modal">
        <div class="modal-header">
          <h3>Your Backup Codes</h3>
          <button @click="showBackupCodesModal = false" class="btn-close">
            <span class="material-symbols-rounded text-xl">close</span>
          </button>
        </div>
        <div class="modal-body space-y-4">
          <div class="p-4 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/30 rounded-xl">
            <div class="flex items-start gap-3">
              <span class="material-symbols-rounded text-amber-500 mt-0.5">warning</span>
              <div>
                <p class="font-medium text-amber-700 dark:text-amber-400">Save these codes in a secure location</p>
                <p class="text-sm text-amber-600 dark:text-amber-300 mt-1">
                  Each code can only be used once. You won't be able to see them again.
                </p>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div
              v-for="code in backupCodes"
              :key="code"
              class="p-3 bg-surface-100 dark:bg-surface-800 rounded-lg font-mono text-center"
            >
              {{ code }}
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button @click="copyBackupCodes" class="btn-secondary">
            <span class="material-symbols-rounded text-lg">content_copy</span>
            Copy All
          </button>
          <button @click="showBackupCodesModal = false" class="btn-primary">Done</button>
        </div>
      </div>
    </div>
  </div>
</template>
