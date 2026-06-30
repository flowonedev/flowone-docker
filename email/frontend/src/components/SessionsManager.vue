<script setup>
import { ref, onMounted, computed } from 'vue'
import { useToastStore } from '@/stores/toast'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'
import { getToken } from '@/services/tokenStorage'

const toast = useToastStore()
const auth = useAuthStore()

const loading = ref(true)
const sessions = ref([])
const trustedDevices = ref([])
const revoking = ref(null)
const revokingDevice = ref(null)

// Tab for sessions vs trusted devices
const activeTab = ref('sessions')

// Get current device info from browser
function getCurrentDeviceInfo() {
  const ua = navigator.userAgent
  let browser = 'Unknown Browser'
  let os = 'Unknown OS'
  
  // Detect browser
  if (/Edg\//.test(ua)) browser = 'Microsoft Edge'
  else if (/Chrome\//.test(ua) && !/Edg\//.test(ua)) browser = 'Google Chrome'
  else if (/Firefox\//.test(ua)) browser = 'Firefox'
  else if (/Safari\//.test(ua) && !/Chrome\//.test(ua)) browser = 'Safari'
  
  // Detect OS
  if (/Windows NT 10/.test(ua)) os = 'Windows 10/11'
  else if (/Windows/.test(ua)) os = 'Windows'
  else if (/Mac OS X/.test(ua)) os = 'macOS'
  else if (/iPhone/.test(ua)) os = 'iOS (iPhone)'
  else if (/iPad/.test(ua)) os = 'iOS (iPad)'
  else if (/Android/.test(ua)) os = 'Android'
  else if (/Linux/.test(ua)) os = 'Linux'
  
  return {
    id: 'current',
    device_name: `${browser} on ${os}`,
    browser,
    os,
    ip_address: null,
    last_active_at: new Date().toISOString(),
    is_current: true,
  }
}

onMounted(async () => {
  await Promise.all([
    loadSessions(),
    loadTrustedDevices(),
  ])
})

async function loadSessions() {
  loading.value = true
  try {
    const sessionToken = getToken('webmail_session_token')
    const response = await api.get('/sessions', {
      headers: sessionToken ? { 'X-Session-Token': sessionToken } : {},
    })
    if (response.data.success) {
      let loadedSessions = response.data.data.sessions || []
      
      // If no sessions from server, show current device as the active session
      // This handles the case where user logged in before session tracking was added
      if (loadedSessions.length === 0) {
        loadedSessions = [getCurrentDeviceInfo()]
      }
      
      sessions.value = loadedSessions
    }
  } catch (e) {
    console.error('Failed to load sessions:', e)
    // Even on error, show current session
    sessions.value = [getCurrentDeviceInfo()]
  } finally {
    loading.value = false
  }
}

async function loadTrustedDevices() {
  try {
    const response = await api.get('/2fa/trusted-devices')
    if (response.data.success) {
      trustedDevices.value = response.data.data.devices || []
    }
  } catch (e) {
    // 2FA might not be enabled
    trustedDevices.value = []
  }
}

async function revokeSession(sessionId) {
  revoking.value = sessionId
  try {
    const response = await api.delete(`/sessions/${sessionId}`)
    if (response.data.success) {
      toast.success('Session revoked')
      sessions.value = sessions.value.filter(s => s.id !== sessionId)
    } else {
      toast.error(response.data.message || 'Failed to revoke session')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to revoke session')
  } finally {
    revoking.value = null
  }
}

async function revokeOtherSessions() {
  revoking.value = 'others'
  try {
    const sessionToken = getToken('webmail_session_token')
    const response = await api.post('/sessions/revoke-others', {}, {
      headers: sessionToken ? { 'X-Session-Token': sessionToken } : {},
    })
    if (response.data.success) {
      toast.success(`${response.data.data.revoked_count} session(s) revoked`)
      await loadSessions()
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to revoke sessions')
  } finally {
    revoking.value = null
  }
}

async function revokeTrustedDevice(deviceId) {
  revokingDevice.value = deviceId
  try {
    const response = await api.delete(`/2fa/trusted-devices/${deviceId}`)
    if (response.data.success) {
      toast.success('Device trust revoked')
      trustedDevices.value = trustedDevices.value.filter(d => d.id !== deviceId)
    } else {
      toast.error(response.data.message || 'Failed to revoke device trust')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to revoke device trust')
  } finally {
    revokingDevice.value = null
  }
}

async function revokeAllTrustedDevices() {
  revokingDevice.value = 'all'
  try {
    const response = await api.delete('/2fa/trusted-devices')
    if (response.data.success) {
      toast.success(`${response.data.data.revoked_count} device(s) trust revoked`)
      trustedDevices.value = []
      // Also remove local device token
      localStorage.removeItem('webmail_device_token')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to revoke all device trusts')
  } finally {
    revokingDevice.value = null
  }
}

function formatDate(dateStr) {
  if (!dateStr) return 'Never'
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now - date
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)
  
  if (diffMins < 1) return 'Just now'
  if (diffMins < 60) return `${diffMins} min ago`
  if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`
  if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`
  
  return date.toLocaleDateString('en-US', { 
    month: 'short', 
    day: 'numeric',
    year: date.getFullYear() !== now.getFullYear() ? 'numeric' : undefined,
  })
}

function formatExpiry(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = date - now
  const diffDays = Math.ceil(diffMs / 86400000)
  
  if (diffDays <= 0) return 'Expired'
  if (diffDays === 1) return 'Expires tomorrow'
  return `Expires in ${diffDays} days`
}

function getDeviceIcon(session) {
  const os = (session.os || '').toLowerCase()
  if (os.includes('iphone') || os.includes('ipad')) return 'phone_iphone'
  if (os.includes('android')) return 'phone_android'
  if (os.includes('mac')) return 'laptop_mac'
  if (os.includes('windows')) return 'laptop_windows'
  if (os.includes('linux')) return 'computer'
  return 'devices'
}

// Other sessions excludes current and synthetic sessions (id='current')
const otherSessions = computed(() => sessions.value.filter(s => !s.is_current && s.id !== 'current'))
</script>

<template>
  <div class="space-y-6">
    <!-- Tabs -->
    <div class="flex gap-2 border-b border-surface-200 dark:border-surface-700">
      <button
        @click="activeTab = 'sessions'"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
          activeTab === 'sessions'
            ? 'border-primary-500 text-primary-600 dark:text-primary-400'
            : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
        ]"
      >
        <span class="material-symbols-rounded text-base align-middle mr-1">devices</span>
        Active Sessions ({{ sessions.length }})
      </button>
      <button
        @click="activeTab = 'trusted'"
        :class="[
          'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
          activeTab === 'trusted'
            ? 'border-primary-500 text-primary-600 dark:text-primary-400'
            : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
        ]"
      >
        <span class="material-symbols-rounded text-base align-middle mr-1">verified_user</span>
        Trusted Devices ({{ trustedDevices.length }})
      </button>
    </div>
    
    <!-- Sessions Tab -->
    <div v-if="activeTab === 'sessions'">
      <div v-if="loading" class="text-center py-8">
        <span class="spinner"></span>
      </div>
      
      <div v-else class="space-y-3">
        <!-- Current session -->
        <div 
          v-for="session in sessions" 
          :key="session.id"
          :class="[
            'p-4 rounded-xl border',
            session.is_current 
              ? 'bg-primary-50 dark:bg-primary-900/20 border-primary-200 dark:border-primary-800' 
              : 'bg-white dark:bg-surface-800 border-surface-200 dark:border-surface-700'
          ]"
        >
          <div class="flex items-start gap-4">
            <div :class="[
              'w-10 h-10 rounded-full flex items-center justify-center',
              session.is_current 
                ? 'bg-primary-500 text-white' 
                : 'bg-surface-200 dark:bg-surface-700 text-surface-600 dark:text-surface-400'
            ]">
              <span class="material-symbols-rounded">{{ getDeviceIcon(session) }}</span>
            </div>
            
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <p class="font-medium text-surface-900 dark:text-surface-100">
                  {{ session.device_name || 'Unknown Device' }}
                </p>
                <span 
                  v-if="session.is_current" 
                  class="px-2 py-0.5 text-xs font-medium bg-primary-500 text-white rounded-full"
                >
                  Current
                </span>
              </div>
              
              <div class="text-sm text-surface-500 mt-1 space-y-0.5">
                <p v-if="session.browser">{{ session.browser }} on {{ session.os }}</p>
                <p v-if="session.ip_address" class="flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm">location_on</span>
                  {{ session.location || session.ip_address }}
                </p>
                <p v-if="session.id !== 'current'">
                  <span class="material-symbols-rounded text-sm align-middle">schedule</span>
                  Last active: {{ formatDate(session.last_active_at) }}
                </p>
                <p v-else class="text-primary-500">
                  <span class="material-symbols-rounded text-sm align-middle">check_circle</span>
                  Active now
                </p>
              </div>
            </div>
            
            <button
              v-if="!session.is_current"
              @click="revokeSession(session.id)"
              :disabled="revoking === session.id"
              class="p-2 rounded-full hover:bg-red-100 dark:hover:bg-red-900/30 text-surface-500 hover:text-red-600 dark:hover:text-red-400 transition-colors"
              title="Revoke this session"
            >
              <span v-if="revoking === session.id" class="spinner text-red-500"></span>
              <span v-else class="material-symbols-rounded">logout</span>
            </button>
          </div>
        </div>
        
        <!-- Revoke all other sessions -->
        <div v-if="otherSessions.length > 0" class="pt-4 border-t border-surface-200 dark:border-surface-700">
          <button
            @click="revokeOtherSessions"
            :disabled="revoking === 'others'"
            class="btn-secondary w-full"
          >
            <span v-if="revoking === 'others'" class="spinner"></span>
            <span class="material-symbols-rounded">logout</span>
            Sign out of {{ otherSessions.length }} other session{{ otherSessions.length > 1 ? 's' : '' }}
          </button>
        </div>
      </div>
    </div>
    
    <!-- Trusted Devices Tab -->
    <div v-if="activeTab === 'trusted'">
      <p class="text-sm text-surface-500 mb-4">
        Trusted devices can skip 2FA verification for 7 days. Remove any device you don't recognize.
      </p>
      
      <div v-if="trustedDevices.length === 0" class="text-center py-8 text-surface-500">
        <span class="material-symbols-rounded text-4xl mb-2 block">security</span>
        <p>No trusted devices</p>
        <p class="text-xs mt-1">You can trust a device when signing in with 2FA</p>
      </div>
      
      <div v-else class="space-y-3">
        <div 
          v-for="device in trustedDevices" 
          :key="device.id"
          class="p-4 bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700"
        >
          <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-full bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 flex items-center justify-center">
              <span class="material-symbols-rounded">verified_user</span>
            </div>
            
            <div class="flex-1 min-w-0">
              <p class="font-medium text-surface-900 dark:text-surface-100">
                {{ device.device_name || 'Unknown Device' }}
              </p>
              
              <div class="text-sm text-surface-500 mt-1 space-y-0.5">
                <p v-if="device.ip_address" class="flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm">location_on</span>
                  {{ device.ip_address }}
                </p>
                <p>
                  <span class="material-symbols-rounded text-sm align-middle">schedule</span>
                  Added: {{ formatDate(device.created_at) }}
                </p>
                <p class="text-green-600 dark:text-green-400">
                  <span class="material-symbols-rounded text-sm align-middle">timer</span>
                  {{ formatExpiry(device.expires_at) }}
                </p>
              </div>
            </div>
            
            <button
              @click="revokeTrustedDevice(device.id)"
              :disabled="revokingDevice === device.id"
              class="p-2 rounded-full hover:bg-red-100 dark:hover:bg-red-900/30 text-surface-500 hover:text-red-600 dark:hover:text-red-400 transition-colors"
              title="Remove trusted device"
            >
              <span v-if="revokingDevice === device.id" class="spinner text-red-500"></span>
              <span v-else class="material-symbols-rounded">remove_circle</span>
            </button>
          </div>
        </div>
        
        <!-- Revoke all trusted devices -->
        <div class="pt-4 border-t border-surface-200 dark:border-surface-700">
          <button
            @click="revokeAllTrustedDevices"
            :disabled="revokingDevice === 'all'"
            class="btn-secondary w-full"
          >
            <span v-if="revokingDevice === 'all'" class="spinner"></span>
            <span class="material-symbols-rounded">security</span>
            Require 2FA on all devices
          </button>
          <p class="text-xs text-surface-500 text-center mt-2">
            This will require 2FA verification on your next login from any device
          </p>
        </div>
      </div>
    </div>
    
    <!-- Sign Out -->
    <div class="mt-6 pt-6 border-t border-surface-200 dark:border-surface-700">
      <div class="flex items-center justify-between">
        <div>
          <p class="font-medium text-surface-700 dark:text-surface-300">Sign out</p>
          <p class="text-sm text-surface-500">End your current session on this browser</p>
        </div>
        <button @click="auth.logout()" class="btn-danger">
          <span class="material-symbols-rounded">logout</span>
          Sign Out
        </button>
      </div>
    </div>
  </div>
</template>

