<script setup>
import { ref, onMounted } from 'vue'
import { useToastStore } from '@/stores/toast'
import api from '@/services/api'
import { isDebugEnabled } from '@/utils/debug'

const toast = useToastStore()

const loading = ref(true)
const enabled = ref(false)
const setupMode = ref(false)
const disableMode = ref(false)

// Setup data
const setupData = ref({
  secret: '',
  qr_code: '',
  backup_codes: [],
})

// Verification
const verifyCode = ref('')
const verifying = ref(false)

// Disable
const disableCode = ref('')
const disablePassword = ref('')
const disabling = ref(false)

// Backup codes
const showBackupCodes = ref(false)
const regeneratingCodes = ref(false)
const regenerateCode = ref('')

onMounted(async () => {
  await checkStatus()
})

async function checkStatus() {
  loading.value = true
  try {
    const response = await api.get('/2fa/status')
    if (response.data.success) {
      enabled.value = response.data.data.enabled
    }
  } catch (e) {
    // 2FA might not be available
    isDebugEnabled() && console.log('2FA status check failed:', e)
  } finally {
    loading.value = false
  }
}

async function startSetup() {
  setupMode.value = true
  try {
    const response = await api.post('/2fa/setup')
    if (response.data.success) {
      setupData.value = response.data.data
    } else {
      toast.error(response.data.message)
      setupMode.value = false
    }
  } catch (e) {
    toast.error('Failed to start 2FA setup')
    setupMode.value = false
  }
}

async function verifySetup() {
  if (!verifyCode.value || verifyCode.value.length !== 6) {
    toast.warning('Please enter a 6-digit code')
    return
  }
  
  verifying.value = true
  try {
    const response = await api.post('/2fa/verify', {
      code: verifyCode.value,
    })
    
    if (response.data.success) {
      toast.success('Two-factor authentication enabled!')
      enabled.value = true
      setupMode.value = false
      verifyCode.value = ''
    } else {
      toast.error(response.data.message || 'Invalid code')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Verification failed')
  } finally {
    verifying.value = false
  }
}

async function disable2FA() {
  if (!disableCode.value || !disablePassword.value) {
    toast.warning('Please enter your verification code and password')
    return
  }
  
  disabling.value = true
  try {
    const response = await api.post('/2fa/disable', {
      code: disableCode.value,
      password: disablePassword.value,
    })
    
    if (response.data.success) {
      toast.success('Two-factor authentication disabled')
      enabled.value = false
      disableMode.value = false
      disableCode.value = ''
      disablePassword.value = ''
    } else {
      toast.error(response.data.message || 'Failed to disable')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to disable 2FA')
  } finally {
    disabling.value = false
  }
}

async function regenerateBackupCodes() {
  if (!regenerateCode.value) {
    toast.warning('Please enter your verification code')
    return
  }
  
  regeneratingCodes.value = true
  try {
    const response = await api.post('/2fa/backup-codes', {
      code: regenerateCode.value,
    })
    
    if (response.data.success) {
      setupData.value.backup_codes = response.data.data.backup_codes
      showBackupCodes.value = true
      regenerateCode.value = ''
      toast.success('New backup codes generated')
    } else {
      toast.error(response.data.message)
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to regenerate codes')
  } finally {
    regeneratingCodes.value = false
  }
}

function cancelSetup() {
  setupMode.value = false
  setupData.value = { secret: '', qr_code: '', backup_codes: [] }
  verifyCode.value = ''
}

function cancelDisable() {
  disableMode.value = false
  disableCode.value = ''
  disablePassword.value = ''
}
</script>

<template>
  <div class="card p-6">
    <div class="flex items-center gap-3 mb-4">
      <span class="material-symbols-rounded text-xl text-surface-500">security</span>
      <h3 class="font-semibold">Two-Factor Authentication</h3>
    </div>
    
    <div v-if="loading" class="py-4">
      <span class="spinner"></span>
    </div>
    
    <!-- Status when enabled -->
    <div v-else-if="enabled && !disableMode">
      <div class="flex items-center gap-2 text-green-600 dark:text-green-400 mb-4">
        <span class="material-symbols-rounded">check_circle</span>
        <span class="font-medium">Enabled</span>
      </div>
      
      <p class="text-sm text-surface-600 dark:text-surface-400 mb-4">
        Your account is protected with two-factor authentication.
      </p>
      
      <div class="flex gap-3">
        <button @click="disableMode = true" class="btn-secondary">
          <span class="material-symbols-rounded">lock_open</span>
          Disable 2FA
        </button>
        
        <button @click="showBackupCodes = true" class="btn-ghost">
          <span class="material-symbols-rounded">key</span>
          Backup Codes
        </button>
      </div>
    </div>
    
    <!-- Disable form -->
    <div v-else-if="disableMode">
      <p class="text-sm text-surface-600 dark:text-surface-400 mb-4">
        Enter your verification code and password to disable two-factor authentication.
      </p>
      
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
            Verification Code
          </label>
          <input
            v-model="disableCode"
            type="text"
            class="input"
            placeholder="Enter 6-digit code"
            maxlength="6"
            pattern="[0-9]*"
            inputmode="numeric"
          />
        </div>
        
        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
            Password
          </label>
          <input
            v-model="disablePassword"
            type="password"
            class="input"
            placeholder="Enter your password"
          />
        </div>
        
        <div class="flex gap-3">
          <button @click="disable2FA" class="btn-danger" :disabled="disabling">
            <span v-if="disabling" class="spinner"></span>
            Disable 2FA
          </button>
          <button @click="cancelDisable" class="btn-ghost">Cancel</button>
        </div>
      </div>
    </div>
    
    <!-- Status when not enabled -->
    <div v-else-if="!setupMode">
      <p class="text-sm text-surface-600 dark:text-surface-400 mb-4">
        Add an extra layer of security to your account by requiring a verification code in addition to your password.
      </p>
      
      <button @click="startSetup" class="btn-primary">
        <span class="material-symbols-rounded">add</span>
        Enable 2FA
      </button>
    </div>
    
    <!-- Setup form -->
    <div v-else>
      <div class="space-y-4">
        <div class="bg-surface-100 dark:bg-surface-800 rounded-lg p-4">
          <p class="text-sm font-medium mb-3">1. Scan this QR code with your authenticator app:</p>
          <div class="flex justify-center mb-3">
            <img :src="setupData.qr_code" alt="QR Code" class="w-48 h-48 bg-white p-2 rounded" />
          </div>
          <p class="text-xs text-surface-500 text-center">
            Or enter manually: <code class="bg-surface-200 dark:bg-surface-700 px-2 py-1 rounded">{{ setupData.secret }}</code>
          </p>
        </div>
        
        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
          <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200 mb-2">
            2. Save these backup codes:
          </p>
          <div class="grid grid-cols-2 gap-2">
            <code 
              v-for="code in setupData.backup_codes" 
              :key="code"
              class="text-sm bg-white dark:bg-surface-800 px-2 py-1 rounded text-center"
            >
              {{ code }}
            </code>
          </div>
          <p class="text-xs text-yellow-700 dark:text-yellow-300 mt-2">
            Store these codes safely. You can use them if you lose access to your authenticator app.
          </p>
        </div>
        
        <div>
          <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-2">
            3. Enter code from authenticator app:
          </label>
          <input
            v-model="verifyCode"
            type="text"
            class="input"
            placeholder="Enter 6-digit code"
            maxlength="6"
            pattern="[0-9]*"
            inputmode="numeric"
            @keyup.enter="verifySetup"
          />
        </div>
        
        <div class="flex gap-3">
          <button @click="verifySetup" class="btn-primary" :disabled="verifying">
            <span v-if="verifying" class="spinner"></span>
            <span class="material-symbols-rounded">check</span>
            Verify & Enable
          </button>
          <button @click="cancelSetup" class="btn-ghost">Cancel</button>
        </div>
      </div>
    </div>
    
    <!-- Backup codes modal -->
    <Teleport to="body">
      <div v-if="showBackupCodes" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" @click.self="showBackupCodes = false">
        <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl max-w-md w-full p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold">Backup Codes</h3>
            <button @click="showBackupCodes = false" class="btn-ghost btn-icon">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
          
          <div v-if="setupData.backup_codes.length > 0" class="mb-4">
            <div class="grid grid-cols-2 gap-2 mb-3">
              <code 
                v-for="code in setupData.backup_codes" 
                :key="code"
                class="text-sm bg-surface-100 dark:bg-surface-700 px-2 py-1 rounded text-center"
              >
                {{ code }}
              </code>
            </div>
            <p class="text-xs text-surface-500">
              Each code can only be used once. Store them securely.
            </p>
          </div>
          
          <div class="border-t border-surface-200 dark:border-surface-700 pt-4 mt-4">
            <p class="text-sm text-surface-600 dark:text-surface-400 mb-3">
              Generate new backup codes (this will invalidate old ones):
            </p>
            <div class="flex gap-2">
              <input
                v-model="regenerateCode"
                type="text"
                class="input flex-1"
                placeholder="Enter 2FA code"
                maxlength="6"
              />
              <button @click="regenerateBackupCodes" class="btn-secondary" :disabled="regeneratingCodes">
                <span v-if="regeneratingCodes" class="spinner"></span>
                <span class="material-symbols-rounded">refresh</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

