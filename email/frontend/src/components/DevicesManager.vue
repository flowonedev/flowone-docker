<script setup>
import { ref, onMounted, computed } from 'vue'
import { useToastStore } from '@/stores/toast'
import api from '@/services/api'

const toast = useToastStore()

const loading = ref(true)
const devices = ref([])
const actionInProgress = ref(null)
const showWipeConfirm = ref(null)

onMounted(async () => {
  await loadDevices()
})

async function loadDevices() {
  loading.value = true
  try {
    const response = await api.get('/devices')
    if (response.data.success) {
      devices.value = response.data.data.devices || []
    }
  } catch (e) {
    console.error('Failed to load devices:', e)
    devices.value = []
  } finally {
    loading.value = false
  }
}

async function blockDevice(device) {
  actionInProgress.value = `block-${device.id}`
  try {
    const response = await api.post(`/devices/${device.id}/block`)
    if (response.data.success) {
      toast.success('Device blocked. All sessions invalidated.')
      await loadDevices()
    } else {
      toast.error(response.data.message || 'Failed to block device')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to block device')
  } finally {
    actionInProgress.value = null
  }
}

async function unblockDevice(device) {
  actionInProgress.value = `unblock-${device.id}`
  try {
    const response = await api.post(`/devices/${device.id}/unblock`)
    if (response.data.success) {
      toast.success('Device unblocked')
      await loadDevices()
    } else {
      toast.error(response.data.message || 'Failed to unblock device')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to unblock device')
  } finally {
    actionInProgress.value = null
  }
}

async function requestWipe(device) {
  actionInProgress.value = `wipe-${device.id}`
  try {
    const response = await api.post(`/devices/${device.id}/wipe`)
    if (response.data.success) {
      toast.success('Remote wipe requested. Device will wipe on next check-in.')
      showWipeConfirm.value = null
      await loadDevices()
    } else {
      toast.error(response.data.message || 'Failed to request wipe')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to request wipe')
  } finally {
    actionInProgress.value = null
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

function getPlatformIcon(platform) {
  switch (platform) {
    case 'desktop': return 'desktop_windows'
    case 'drive': return 'hard_drive'
    case 'web': return 'language'
    default: return 'devices'
  }
}

function getPlatformLabel(platform) {
  switch (platform) {
    case 'desktop': return 'FlowOne Email'
    case 'drive': return 'FlowOne Drive'
    case 'web': return 'Web Browser'
    default: return 'Unknown'
  }
}

function getStatusColor(status) {
  switch (status) {
    case 'active': return 'text-green-600 dark:text-green-400 bg-green-100 dark:bg-green-900/30'
    case 'blocked': return 'text-red-600 dark:text-red-400 bg-red-100 dark:bg-red-900/30'
    case 'wipe_pending': return 'text-amber-600 dark:text-amber-400 bg-amber-100 dark:bg-amber-900/30'
    case 'wiped': return 'text-surface-500 bg-surface-100 dark:bg-surface-800'
    default: return 'text-surface-500 bg-surface-100 dark:bg-surface-800'
  }
}

function getStatusLabel(status) {
  switch (status) {
    case 'active': return 'Active'
    case 'blocked': return 'Blocked'
    case 'wipe_pending': return 'Wipe Pending'
    case 'wiped': return 'Wiped'
    default: return status
  }
}

function getOSIcon(os) {
  if (!os) return 'help'
  const lower = os.toLowerCase()
  if (lower.includes('windows')) return 'laptop_windows'
  if (lower.includes('mac')) return 'laptop_mac'
  if (lower.includes('linux')) return 'computer'
  if (lower.includes('iphone') || lower.includes('ios')) return 'phone_iphone'
  if (lower.includes('android')) return 'phone_android'
  return 'devices'
}

const desktopDevices = computed(() => devices.value.filter(d => d.platform !== 'web'))
const webDevices = computed(() => devices.value.filter(d => d.platform === 'web'))
</script>

<template>
  <div class="space-y-6">
    <!-- Loading -->
    <div v-if="loading" class="text-center py-8">
      <span class="spinner"></span>
    </div>
    
    <template v-else>
      <!-- No devices -->
      <div v-if="devices.length === 0" class="text-center py-8 text-surface-500">
        <span class="material-symbols-rounded text-4xl mb-2 block">devices</span>
        <p>No registered devices</p>
        <p class="text-xs mt-1">Devices will appear here when you log in from desktop or drive apps</p>
      </div>
      
      <template v-else>
        <!-- Desktop & Drive Devices -->
        <div v-if="desktopDevices.length > 0">
          <h3 class="text-sm font-semibold text-surface-500 uppercase tracking-wider mb-3">
            Desktop & Drive Apps
          </h3>
          <div class="space-y-3">
            <div 
              v-for="device in desktopDevices" 
              :key="device.id"
              class="p-4 bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700"
            >
              <div class="flex items-start gap-4">
                <!-- Icon -->
                <div :class="[
                  'w-10 h-10 rounded-full flex items-center justify-center',
                  device.status === 'active'
                    ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-600 dark:text-primary-400'
                    : 'bg-surface-200 dark:bg-surface-700 text-surface-500'
                ]">
                  <span class="material-symbols-rounded">{{ getPlatformIcon(device.platform) }}</span>
                </div>
                
                <!-- Info -->
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 flex-wrap">
                    <p class="font-medium text-surface-900 dark:text-surface-100">
                      {{ device.device_name || getPlatformLabel(device.platform) }}
                    </p>
                    <span :class="['px-2 py-0.5 text-xs font-medium rounded-full', getStatusColor(device.status)]">
                      {{ getStatusLabel(device.status) }}
                    </span>
                  </div>
                  
                  <div class="text-sm text-surface-500 mt-1 space-y-0.5">
                    <p class="flex items-center gap-1">
                      <span class="material-symbols-rounded text-sm">{{ getOSIcon(device.os) }}</span>
                      {{ device.os || 'Unknown OS' }}
                      <span v-if="device.app_version" class="text-surface-400">v{{ device.app_version }}</span>
                    </p>
                    <p v-if="device.last_ip" class="flex items-center gap-1">
                      <span class="material-symbols-rounded text-sm">location_on</span>
                      {{ device.last_ip }}
                    </p>
                    <p class="flex items-center gap-1">
                      <span class="material-symbols-rounded text-sm">schedule</span>
                      Last seen: {{ formatDate(device.last_seen_at) }}
                    </p>
                    <p v-if="device.wipe_requested_at" class="flex items-center gap-1 text-amber-600 dark:text-amber-400">
                      <span class="material-symbols-rounded text-sm">warning</span>
                      Wipe requested: {{ formatDate(device.wipe_requested_at) }}
                    </p>
                    <p v-if="device.wipe_confirmed_at" class="flex items-center gap-1 text-green-600 dark:text-green-400">
                      <span class="material-symbols-rounded text-sm">check_circle</span>
                      Wipe confirmed: {{ formatDate(device.wipe_confirmed_at) }}
                    </p>
                  </div>
                </div>
                
                <!-- Actions -->
                <div class="flex items-center gap-1">
                  <!-- Block / Unblock -->
                  <button
                    v-if="device.status === 'active'"
                    @click="blockDevice(device)"
                    :disabled="actionInProgress === `block-${device.id}`"
                    class="p-2 rounded-full hover:bg-red-100 dark:hover:bg-red-900/30 text-surface-500 hover:text-red-600 dark:hover:text-red-400 transition-colors"
                    title="Block this device"
                  >
                    <span v-if="actionInProgress === `block-${device.id}`" class="spinner text-red-500"></span>
                    <span v-else class="material-symbols-rounded">block</span>
                  </button>
                  
                  <button
                    v-if="device.status === 'blocked'"
                    @click="unblockDevice(device)"
                    :disabled="actionInProgress === `unblock-${device.id}`"
                    class="p-2 rounded-full hover:bg-green-100 dark:hover:bg-green-900/30 text-surface-500 hover:text-green-600 dark:hover:text-green-400 transition-colors"
                    title="Unblock this device"
                  >
                    <span v-if="actionInProgress === `unblock-${device.id}`" class="spinner text-green-500"></span>
                    <span v-else class="material-symbols-rounded">check_circle</span>
                  </button>
                  
                  <!-- Remote Wipe -->
                  <button
                    v-if="device.status === 'active' || device.status === 'blocked'"
                    @click="showWipeConfirm = device.id"
                    class="p-2 rounded-full hover:bg-red-100 dark:hover:bg-red-900/30 text-surface-500 hover:text-red-600 dark:hover:text-red-400 transition-colors"
                    title="Remote wipe this device"
                  >
                    <span class="material-symbols-rounded">delete_forever</span>
                  </button>
                </div>
              </div>
              
              <!-- Wipe Confirmation -->
              <div 
                v-if="showWipeConfirm === device.id"
                class="mt-4 p-3 bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800"
              >
                <p class="text-sm text-red-700 dark:text-red-300 font-medium mb-2">
                  <span class="material-symbols-rounded text-sm align-middle mr-1">warning</span>
                  Confirm Remote Wipe
                </p>
                <p class="text-xs text-red-600 dark:text-red-400 mb-3">
                  This will delete all cached emails, synced files, tokens, and local data on this device. 
                  The wipe will execute when the device next connects to the server.
                </p>
                <div class="flex gap-2">
                  <button
                    @click="requestWipe(device)"
                    :disabled="actionInProgress === `wipe-${device.id}`"
                    class="px-3 py-1.5 text-sm font-medium rounded-full bg-red-600 hover:bg-red-700 text-white transition-colors"
                  >
                    <span v-if="actionInProgress === `wipe-${device.id}`" class="spinner"></span>
                    <span class="material-symbols-rounded text-sm align-middle mr-1">delete_forever</span>
                    Wipe Device
                  </button>
                  <button
                    @click="showWipeConfirm = null"
                    class="px-3 py-1.5 text-sm font-medium rounded-full bg-surface-200 dark:bg-surface-700 text-surface-700 dark:text-surface-300 hover:bg-surface-300 dark:hover:bg-surface-600 transition-colors"
                  >
                    Cancel
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Web Devices -->
        <div v-if="webDevices.length > 0">
          <h3 class="text-sm font-semibold text-surface-500 uppercase tracking-wider mb-3">
            Web Sessions
          </h3>
          <div class="space-y-3">
            <div 
              v-for="device in webDevices" 
              :key="device.id"
              class="p-4 bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700"
            >
              <div class="flex items-start gap-4">
                <div class="w-10 h-10 rounded-full bg-surface-200 dark:bg-surface-700 text-surface-500 flex items-center justify-center">
                  <span class="material-symbols-rounded">language</span>
                </div>
                
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2">
                    <p class="font-medium text-surface-900 dark:text-surface-100">
                      {{ device.device_name || 'Web Browser' }}
                    </p>
                    <span :class="['px-2 py-0.5 text-xs font-medium rounded-full', getStatusColor(device.status)]">
                      {{ getStatusLabel(device.status) }}
                    </span>
                  </div>
                  
                  <div class="text-sm text-surface-500 mt-1 space-y-0.5">
                    <p v-if="device.os" class="flex items-center gap-1">
                      <span class="material-symbols-rounded text-sm">{{ getOSIcon(device.os) }}</span>
                      {{ device.os }}
                    </p>
                    <p v-if="device.last_ip" class="flex items-center gap-1">
                      <span class="material-symbols-rounded text-sm">location_on</span>
                      {{ device.last_ip }}
                    </p>
                    <p class="flex items-center gap-1">
                      <span class="material-symbols-rounded text-sm">schedule</span>
                      Last seen: {{ formatDate(device.last_seen_at) }}
                    </p>
                  </div>
                </div>
                
                <!-- Block for web devices -->
                <button
                  v-if="device.status === 'active'"
                  @click="blockDevice(device)"
                  :disabled="actionInProgress === `block-${device.id}`"
                  class="p-2 rounded-full hover:bg-red-100 dark:hover:bg-red-900/30 text-surface-500 hover:text-red-600 dark:hover:text-red-400 transition-colors"
                  title="Block this device"
                >
                  <span v-if="actionInProgress === `block-${device.id}`" class="spinner text-red-500"></span>
                  <span v-else class="material-symbols-rounded">block</span>
                </button>
                <button
                  v-if="device.status === 'blocked'"
                  @click="unblockDevice(device)"
                  :disabled="actionInProgress === `unblock-${device.id}`"
                  class="p-2 rounded-full hover:bg-green-100 dark:hover:bg-green-900/30 text-surface-500 hover:text-green-600 dark:hover:text-green-400 transition-colors"
                  title="Unblock this device"
                >
                  <span v-if="actionInProgress === `unblock-${device.id}`" class="spinner text-green-500"></span>
                  <span v-else class="material-symbols-rounded">check_circle</span>
                </button>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Info box -->
        <div class="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
          <p class="text-xs text-blue-700 dark:text-blue-300">
            <span class="material-symbols-rounded text-sm align-middle mr-1">info</span>
            <strong>Block</strong> prevents login from that device. 
            <strong>Remote Wipe</strong> deletes all local data (emails, files, tokens) on the next app check-in. 
            Use this if a laptop is lost or stolen.
          </p>
        </div>
      </template>
    </template>
  </div>
</template>

