<template>
  <div class="space-y-8">
    <!-- Header -->
    <div>
      <h1 class="text-2xl font-bold">Settings</h1>
      <p class="text-surface-500 mt-1">Manage your account security and preferences</p>
    </div>

    <!-- Tabs -->
    <div class="border-b border-surface-200 dark:border-surface-700">
      <nav class="tab-nav">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="['tab-btn', activeTab === tab.id ? 'active' : '']"
        >
          <span class="material-symbols-rounded text-lg">{{ tab.icon }}</span>
          <span class="tab-label">{{ tab.label }}</span>
        </button>
      </nav>
    </div>

    <!-- Password Tab -->
    <div v-if="activeTab === 'password'" class="max-w-xl">
      <div class="card p-6">
        <h3 class="font-semibold mb-6">Change Password</h3>
        
        <form @submit.prevent="changePassword" class="space-y-4">
          <div>
            <label class="block text-sm font-medium mb-2">Current Password</label>
            <input
              v-model="passwordForm.current"
              type="password"
              class="input"
              required
            />
          </div>
          
          <div>
            <label class="block text-sm font-medium mb-2">New Password</label>
            <input
              v-model="passwordForm.new"
              type="password"
              class="input"
              minlength="8"
              required
            />
            <p class="text-xs text-surface-500 mt-1">Minimum 12 characters, must include uppercase, lowercase, number, and special character</p>
          </div>
          
          <div>
            <label class="block text-sm font-medium mb-2">Confirm New Password</label>
            <input
              v-model="passwordForm.confirm"
              type="password"
              class="input"
              required
            />
          </div>
          
          <div v-if="passwordError" class="text-sm text-red-500">
            {{ passwordError }}
          </div>
          
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Update Password
          </button>
        </form>
      </div>
    </div>

    <!-- 2FA Tab -->
    <div v-if="activeTab === '2fa'" class="max-w-2xl space-y-6">
      <!-- 2FA Status Card -->
      <div class="card p-6">
        <div class="flex items-start justify-between">
          <div class="flex items-start gap-4">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center"
                 :class="twoFaStatus.enabled 
                   ? 'bg-green-100 dark:bg-green-500/20' 
                   : 'bg-surface-100 dark:bg-surface-700'">
              <span class="material-symbols-rounded text-2xl"
                    :class="twoFaStatus.enabled ? 'text-green-600 dark:text-green-400' : 'text-surface-400'">
                {{ twoFaStatus.enabled ? 'verified_user' : 'shield' }}
              </span>
            </div>
            <div>
              <h3 class="font-semibold">Two-Factor Authentication</h3>
              <p class="text-sm text-surface-500 mt-1">
                {{ twoFaStatus.enabled 
                  ? 'Your account is protected with 2FA' 
                  : 'Add an extra layer of security to your account' }}
              </p>
              <div v-if="twoFaStatus.enabled" class="mt-2 text-sm">
                <span class="text-surface-500">Backup codes remaining:</span>
                <span class="ml-1 font-medium" :class="twoFaStatus.backup_codes_remaining <= 2 ? 'text-amber-500' : ''">
                  {{ twoFaStatus.backup_codes_remaining }}
                </span>
              </div>
            </div>
          </div>
          
          <div v-if="!twoFaStatus.enabled">
            <button @click="startSetup2FA" class="btn-primary" :disabled="submitting">
              Enable 2FA
            </button>
          </div>
          <div v-else class="flex gap-2">
            <button @click="showRegenerateCodesModal = true" class="btn-secondary btn-sm">
              New Backup Codes
            </button>
            <button @click="showDisable2FAModal = true" class="btn-danger btn-sm">
              Disable
            </button>
          </div>
        </div>
      </div>

      <!-- Setup 2FA Modal Flow -->
      <div v-if="setupStep > 0" class="card p-6">
        <!-- Step 1: Scan QR Code -->
        <div v-if="setupStep === 1" class="space-y-6">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-primary-500 text-white flex items-center justify-center text-sm font-medium">1</div>
            <h3 class="font-semibold">Scan QR Code</h3>
          </div>
          
          <p class="text-sm text-surface-500">
            Scan this QR code with your authenticator app (Google Authenticator, Authy, etc.)
          </p>
          
          <div class="flex justify-center">
            <div class="p-4 bg-white rounded-xl">
              <img :src="qrCodeDataUrl" alt="2FA QR Code" class="w-48 h-48" />
            </div>
          </div>
          
          <div class="text-center">
            <button @click="showManualEntry = !showManualEntry" class="text-sm text-primary-500 hover:underline">
              Can't scan? Enter manually
            </button>
          </div>
          
          <div v-if="showManualEntry" class="bg-surface-50 dark:bg-surface-800/50 rounded-lg p-4">
            <p class="text-sm font-medium mb-2">Manual Entry</p>
            <div class="space-y-2 text-sm">
              <div class="flex justify-between">
                <span class="text-surface-500">Account:</span>
                <span class="font-mono">{{ setupData?.manual_entry?.account }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-surface-500">Secret:</span>
                <code class="font-mono bg-surface-200 dark:bg-surface-700 px-2 py-0.5 rounded select-all">
                  {{ setupData?.secret }}
                </code>
              </div>
            </div>
          </div>
          
          <div class="flex justify-end">
            <button @click="setupStep = 2" class="btn-primary">
              Continue
            </button>
          </div>
        </div>

        <!-- Step 2: Verify Code -->
        <div v-if="setupStep === 2" class="space-y-6">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-primary-500 text-white flex items-center justify-center text-sm font-medium">2</div>
            <h3 class="font-semibold">Verify Setup</h3>
          </div>
          
          <p class="text-sm text-surface-500">
            Enter the 6-digit code from your authenticator app to verify setup
          </p>
          
          <form @submit.prevent="verifyAndEnable2FA" class="space-y-4">
            <div>
              <input
                v-model="verificationCode"
                type="text"
                class="input text-center text-2xl tracking-widest font-mono"
                placeholder="000000"
                maxlength="6"
                pattern="[0-9]{6}"
                autocomplete="one-time-code"
                required
              />
            </div>
            
            <div v-if="verifyError" class="text-sm text-red-500 text-center">
              {{ verifyError }}
            </div>
            
            <div class="flex justify-between">
              <button type="button" @click="setupStep = 1" class="btn-secondary">
                Back
              </button>
              <button type="submit" class="btn-primary" :disabled="submitting || verificationCode.length !== 6">
                <span v-if="submitting" class="spinner"></span>
                Verify & Enable
              </button>
            </div>
          </form>
        </div>

        <!-- Step 3: Backup Codes -->
        <div v-if="setupStep === 3" class="space-y-6">
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-green-500 text-white flex items-center justify-center">
              <span class="material-symbols-rounded text-lg">check</span>
            </div>
            <h3 class="font-semibold text-green-600 dark:text-green-400">2FA Enabled Successfully</h3>
          </div>
          
          <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
            <div class="flex gap-3">
              <span class="material-symbols-rounded text-amber-500">warning</span>
              <div class="text-sm">
                <p class="font-medium text-amber-800 dark:text-amber-200">Save your backup codes</p>
                <p class="text-amber-700 dark:text-amber-300 mt-1">
                  Store these codes in a safe place. You can use them to access your account if you lose your authenticator device.
                </p>
              </div>
            </div>
          </div>
          
          <div class="bg-surface-50 dark:bg-surface-800/50 rounded-lg p-4">
            <div class="grid grid-cols-2 gap-2">
              <code 
                v-for="code in backupCodes" 
                :key="code"
                class="font-mono text-center py-2 bg-white dark:bg-surface-700 rounded border border-surface-200 dark:border-surface-600"
              >
                {{ code }}
              </code>
            </div>
          </div>
          
          <div class="flex justify-between">
            <button @click="downloadBackupCodes" class="btn-secondary">
              <span class="material-symbols-rounded">download</span>
              Download Codes
            </button>
            <button @click="setupStep = 0" class="btn-primary">
              Done
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Sessions Tab -->
    <div v-if="activeTab === 'sessions'" class="space-y-6">
      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
        <h3 class="font-semibold">Active Sessions</h3>
        <button 
          v-if="sessions.length > 1"
          @click="revokeAllSessions" 
          class="btn-danger btn-sm"
          :disabled="submitting"
        >
          <span class="material-symbols-rounded">logout</span>
          <span class="hidden sm:inline">Sign Out Other Sessions</span>
          <span class="sm:hidden">Sign Out Others</span>
        </button>
      </div>

      <div class="card overflow-hidden">
        <div class="table-responsive">
        <table class="table">
          <thead>
            <tr class="bg-surface-50 dark:bg-surface-800/50">
              <th>Device</th>
              <th>IP Address</th>
              <th>Last Activity</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="session in sessions" :key="session.id" 
                :class="session.is_current ? 'bg-primary-50/50 dark:bg-primary-500/10' : ''">
              <td>
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-xl flex items-center justify-center"
                       :class="session.is_current 
                         ? 'bg-primary-100 dark:bg-primary-500/20' 
                         : 'bg-surface-100 dark:bg-surface-700'">
                    <span class="material-symbols-rounded"
                          :class="session.is_current ? 'text-primary-600 dark:text-primary-400' : 'text-surface-500'">
                      {{ getDeviceIcon(session.browser?.os) }}
                    </span>
                  </div>
                  <div>
                    <div class="font-medium flex items-center gap-2">
                      {{ session.device_name || session.browser?.browser + ' on ' + session.browser?.os }}
                      <span v-if="session.is_current" class="text-xs px-2 py-0.5 rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400">
                        Current
                      </span>
                    </div>
                    <div class="text-xs text-surface-500 truncate max-w-xs">
                      {{ session.user_agent?.substring(0, 60) }}...
                    </div>
                  </div>
                </div>
              </td>
              <td>
                <code class="text-sm font-mono">{{ session.ip_address }}</code>
              </td>
              <td>
                <div class="text-sm">{{ formatDate(session.last_activity) }}</div>
                <div class="text-xs text-surface-500">Created: {{ formatDate(session.created_at) }}</div>
              </td>
              <td class="text-right">
                <button 
                  v-if="!session.is_current"
                  @click="revokeSession(session.id)"
                  class="btn-ghost btn-sm text-red-500"
                  :disabled="submitting"
                >
                  <span class="material-symbols-rounded">logout</span>
                </button>
                <span v-else class="text-xs text-surface-400">Active now</span>
              </td>
            </tr>
            <tr v-if="!sessions.length">
              <td colspan="4" class="py-8 text-center text-surface-400">
                No active sessions
              </td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- Disable 2FA Modal -->
    <Modal :show="showDisable2FAModal" title="Disable Two-Factor Authentication" @close="showDisable2FAModal = false">
      <form @submit.prevent="disable2FA" class="space-y-4">
        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
          <div class="flex gap-3">
            <span class="material-symbols-rounded text-amber-500">warning</span>
            <p class="text-sm text-amber-800 dark:text-amber-200">
              Disabling 2FA will make your account less secure. Are you sure you want to continue?
            </p>
          </div>
        </div>
        
        <div>
          <label class="block text-sm font-medium mb-2">Enter your password to confirm</label>
          <input
            v-model="disablePassword"
            type="password"
            class="input"
            required
          />
        </div>
        
        <div class="flex justify-end gap-3">
          <button type="button" @click="showDisable2FAModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-danger" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Disable 2FA
          </button>
        </div>
      </form>
    </Modal>

    <!-- Regenerate Backup Codes Modal -->
    <Modal :show="showRegenerateCodesModal" title="Regenerate Backup Codes" @close="closeRegenerateModal">
      <div v-if="!newBackupCodes.length">
        <form @submit.prevent="regenerateBackupCodes" class="space-y-4">
          <p class="text-sm text-surface-500">
            This will invalidate your current backup codes and generate new ones.
          </p>
          
          <div>
            <label class="block text-sm font-medium mb-2">Enter your password to confirm</label>
            <input
              v-model="regeneratePassword"
              type="password"
              class="input"
              required
            />
          </div>
          
          <div class="flex justify-end gap-3">
            <button type="button" @click="showRegenerateCodesModal = false" class="btn-secondary">
              Cancel
            </button>
            <button type="submit" class="btn-primary" :disabled="submitting">
              <span v-if="submitting" class="spinner"></span>
              Generate New Codes
            </button>
          </div>
        </form>
      </div>
      
      <div v-else class="space-y-4">
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
          <div class="flex gap-3">
            <span class="material-symbols-rounded text-green-500">check_circle</span>
            <p class="text-sm text-green-800 dark:text-green-200">
              New backup codes generated successfully. Save them in a safe place.
            </p>
          </div>
        </div>
        
        <div class="bg-surface-50 dark:bg-surface-800/50 rounded-lg p-4">
          <div class="grid grid-cols-2 gap-2">
            <code 
              v-for="code in newBackupCodes" 
              :key="code"
              class="font-mono text-center py-2 bg-white dark:bg-surface-700 rounded border border-surface-200 dark:border-surface-600"
            >
              {{ code }}
            </code>
          </div>
        </div>
        
        <div class="flex justify-between">
          <button @click="downloadNewBackupCodes" class="btn-secondary">
            <span class="material-symbols-rounded">download</span>
            Download Codes
          </button>
          <button @click="closeRegenerateModal" class="btn-primary">
            Done
          </button>
        </div>
      </div>
    </Modal>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import Modal from '@/components/Modal.vue'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const router = useRouter()
const toast = useToastStore()
const auth = useAuthStore()

const tabs = [
  { id: 'password', label: 'Password', icon: 'key' },
  { id: '2fa', label: 'Two-Factor Auth', icon: 'verified_user' },
  { id: 'sessions', label: 'Sessions', icon: 'devices' },
]

const activeTab = ref('password')
const submitting = ref(false)

// Password form
const passwordForm = ref({
  current: '',
  new: '',
  confirm: '',
})
const passwordError = ref('')

// 2FA state
const twoFaStatus = ref({ enabled: false, backup_codes_remaining: 0 })
const setupStep = ref(0)
const setupData = ref(null)
const qrCodeDataUrl = ref('')
const showManualEntry = ref(false)
const verificationCode = ref('')
const verifyError = ref('')
const backupCodes = ref([])

// 2FA modals
const showDisable2FAModal = ref(false)
const disablePassword = ref('')
const showRegenerateCodesModal = ref(false)
const regeneratePassword = ref('')
const newBackupCodes = ref([])

// Sessions
const sessions = ref([])

onMounted(async () => {
  await Promise.all([
    load2FAStatus(),
    loadSessions(),
  ])
})

// Password methods
const changePassword = async () => {
  passwordError.value = ''
  
  if (passwordForm.value.new !== passwordForm.value.confirm) {
    passwordError.value = 'Passwords do not match'
    return
  }
  
  if (passwordForm.value.new.length < 12) {
    passwordError.value = 'Password must be at least 12 characters'
    return
  }
  
  submitting.value = true
  try {
    await api.post('/auth/password', {
      current_password: passwordForm.value.current,
      new_password: passwordForm.value.new,
    })
    toast.success('Password changed successfully. Please login again.')
    auth.logout()
    router.push('/login')
  } catch (e) {
    passwordError.value = e.response?.data?.error || 'Failed to change password'
  } finally {
    submitting.value = false
  }
}

// 2FA methods
const load2FAStatus = async () => {
  try {
    const response = await api.get('/auth/2fa/status')
    if (response.data.success) {
      twoFaStatus.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to load 2FA status:', e)
  }
}

const startSetup2FA = async () => {
  submitting.value = true
  try {
    const response = await api.post('/auth/2fa/setup')
    if (response.data.success) {
      setupData.value = response.data.data
      // Generate QR code using QRServer API (free, no API key needed)
      qrCodeDataUrl.value = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(setupData.value.qr_url)}`
      setupStep.value = 1
    }
  } catch (e) {
    toast.error('Failed to initialize 2FA setup')
  } finally {
    submitting.value = false
  }
}

const verifyAndEnable2FA = async () => {
  verifyError.value = ''
  submitting.value = true
  
  try {
    const response = await api.post('/auth/2fa/enable', {
      totp_code: verificationCode.value,
    })
    if (response.data.success) {
      backupCodes.value = response.data.data.backup_codes
      setupStep.value = 3
      await load2FAStatus()
    }
  } catch (e) {
    verifyError.value = e.response?.data?.error || 'Invalid verification code'
  } finally {
    submitting.value = false
  }
}

const disable2FA = async () => {
  submitting.value = true
  try {
    const response = await api.post('/auth/2fa/disable', {
      password: disablePassword.value,
    })
    if (response.data.success) {
      toast.success('2FA has been disabled')
      showDisable2FAModal.value = false
      disablePassword.value = ''
      await load2FAStatus()
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to disable 2FA')
  } finally {
    submitting.value = false
  }
}

const regenerateBackupCodes = async () => {
  submitting.value = true
  try {
    const response = await api.post('/auth/2fa/backup-codes', {
      password: regeneratePassword.value,
    })
    if (response.data.success) {
      newBackupCodes.value = response.data.data.backup_codes
      regeneratePassword.value = ''
      await load2FAStatus()
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to regenerate backup codes')
  } finally {
    submitting.value = false
  }
}

const closeRegenerateModal = () => {
  showRegenerateCodesModal.value = false
  newBackupCodes.value = []
  regeneratePassword.value = ''
}

const downloadBackupCodes = () => {
  downloadCodes(backupCodes.value)
}

const downloadNewBackupCodes = () => {
  downloadCodes(newBackupCodes.value)
}

const downloadCodes = (codes) => {
  const content = `VPS Admin Backup Codes\n${'='.repeat(30)}\n\nStore these codes in a safe place.\nEach code can only be used once.\n\n${codes.join('\n')}\n\nGenerated: ${new Date().toISOString()}`
  const blob = new Blob([content], { type: 'text/plain' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'vps-admin-backup-codes.txt'
  a.click()
  URL.revokeObjectURL(url)
}

// Session methods
const loadSessions = async () => {
  try {
    const response = await api.get('/auth/sessions', {
      params: { refresh_token: auth.refreshToken }
    })
    if (response.data.success) {
      sessions.value = response.data.data.sessions
    }
  } catch (e) {
    console.error('Failed to load sessions:', e)
  }
}

const revokeSession = async (sessionId) => {
  submitting.value = true
  try {
    const response = await api.delete(`/auth/sessions/${sessionId}`)
    if (response.data.success) {
      toast.success('Session revoked')
      await loadSessions()
    }
  } catch (e) {
    toast.error('Failed to revoke session')
  } finally {
    submitting.value = false
  }
}

const revokeAllSessions = async () => {
  submitting.value = true
  try {
    const response = await api.post('/auth/sessions/revoke-all', {
      refresh_token: auth.refreshToken
    })
    if (response.data.success) {
      toast.success(response.data.message)
      await loadSessions()
    }
  } catch (e) {
    toast.error('Failed to revoke sessions')
  } finally {
    submitting.value = false
  }
}

const getDeviceIcon = (os) => {
  if (!os) return 'devices'
  if (os.includes('Windows')) return 'computer'
  if (os.includes('Mac') || os.includes('macOS')) return 'laptop_mac'
  if (os.includes('Linux')) return 'terminal'
  if (os.includes('iOS') || os.includes('iPhone')) return 'phone_iphone'
  if (os.includes('Android')) return 'phone_android'
  return 'devices'
}

const formatDate = (dateStr) => {
  if (!dateStr) return 'N/A'
  const date = new Date(dateStr)
  const now = new Date()
  const diff = now - date
  
  if (diff < 60000) return 'Just now'
  if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`
  if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`
  
  return date.toLocaleDateString('en-US', { 
    month: 'short', 
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}
</script>

