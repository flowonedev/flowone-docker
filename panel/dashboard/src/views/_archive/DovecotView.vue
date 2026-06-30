<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()

const loading = ref(true)
const saving = ref(false)
const status = ref(null)
const settings = ref({})
const originalSettings = ref({})
const editMode = ref(false)
const protocols = ref([])
const connections = ref([])

// Common Dovecot settings to display/edit
const settingDefinitions = [
  { key: 'mail_location', label: 'Mail Location', description: 'Path to mailbox storage', placeholder: 'maildir:~/Maildir' },
  { key: 'mail_max_userip_connections', label: 'Max Connections per IP', description: 'Maximum connections per user from single IP', placeholder: '10' },
  { key: 'default_process_limit', label: 'Default Process Limit', description: 'Maximum number of service processes', placeholder: '100' },
  { key: 'default_client_limit', label: 'Default Client Limit', description: 'Maximum number of client connections per process', placeholder: '1000' },
  { key: 'auth_mechanisms', label: 'Auth Mechanisms', description: 'Allowed authentication mechanisms', placeholder: 'plain login' },
  { key: 'ssl', label: 'SSL Mode', description: 'SSL/TLS mode for connections', placeholder: 'required', type: 'select', options: ['no', 'yes', 'required'] },
  { key: 'ssl_min_protocol', label: 'SSL Min Protocol', description: 'Minimum TLS version', placeholder: 'TLSv1.2', type: 'select', options: ['TLSv1', 'TLSv1.1', 'TLSv1.2', 'TLSv1.3'] },
  { key: 'verbose_ssl', label: 'Verbose SSL', description: 'Log SSL handshakes and errors', placeholder: 'no', type: 'toggle' },
  { key: 'auth_verbose', label: 'Verbose Auth', description: 'Log authentication attempts', placeholder: 'no', type: 'toggle' },
  { key: 'mail_debug', label: 'Mail Debug', description: 'Enable mail debugging', placeholder: 'no', type: 'toggle' },
]

const hasChanges = computed(() => {
  return JSON.stringify(settings.value) !== JSON.stringify(originalSettings.value)
})

const fetchStatus = async () => {
  try {
    const response = await api.get('/dovecot/status')
    if (response.data.success) {
      status.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch Dovecot status', e)
  }
}

const fetchSettings = async () => {
  loading.value = true
  try {
    const response = await api.get('/dovecot/settings')
    if (response.data.success) {
      settings.value = response.data.data.settings || {}
      originalSettings.value = { ...settings.value }
      protocols.value = response.data.data.protocols || []
      connections.value = response.data.data.connections || []
    }
  } catch (e) {
    toast.error('Failed to load Dovecot settings')
  } finally {
    loading.value = false
  }
}

const saveSettings = async () => {
  saving.value = true
  try {
    const response = await api.put('/dovecot/settings', {
      settings: settings.value
    })
    if (response.data.success) {
      toast.success('Dovecot settings saved successfully')
      originalSettings.value = { ...settings.value }
      editMode.value = false
    } else {
      toast.error(response.data.error || 'Failed to save settings')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to save settings')
  } finally {
    saving.value = false
  }
}

const cancelEdit = () => {
  settings.value = { ...originalSettings.value }
  editMode.value = false
}

const restartDovecot = async () => {
  saving.value = true
  try {
    const response = await api.post('/dovecot/restart')
    if (response.data.success) {
      toast.success('Dovecot restarted successfully')
      await fetchStatus()
    } else {
      toast.error(response.data.error || 'Failed to restart Dovecot')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restart Dovecot')
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchStatus()
  fetchSettings()
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Dovecot Configuration</h1>
        <p class="page-subtitle">Manage IMAP/POP3 mail server settings</p>
      </div>
    </div>

    <!-- Status Card -->
    <div class="card p-6 mb-6">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-6">
          <div class="flex items-center gap-3">
            <div :class="[
              'w-3 h-3 rounded-full',
              status?.running ? 'bg-green-500' : 'bg-red-500'
            ]" />
            <span class="font-medium">
              {{ status?.running ? 'Running' : 'Stopped' }}
            </span>
          </div>
          
          <div v-if="status?.version" class="text-surface-500">
            Version: <span class="font-mono">{{ status.version }}</span>
          </div>
          
          <div class="text-surface-500">
            Active: <span class="font-mono">{{ connections.length }} connections</span>
          </div>
        </div>
        
        <div class="flex items-center gap-3">
          <button 
            @click="restartDovecot"
            class="btn btn-secondary"
            :disabled="saving"
          >
            <span class="material-symbols-rounded">refresh</span>
            Restart Dovecot
          </button>
          
          <button 
            v-if="!editMode"
            @click="editMode = true"
            class="btn btn-primary"
            :disabled="loading"
          >
            <span class="material-symbols-rounded">edit</span>
            Edit Settings
          </button>
          
          <template v-else>
            <button 
              @click="cancelEdit"
              class="btn btn-secondary"
              :disabled="saving"
            >
              Cancel
            </button>
            <button 
              @click="saveSettings"
              class="btn btn-primary"
              :disabled="saving || !hasChanges"
            >
              <span v-if="saving" class="material-symbols-rounded animate-spin">progress_activity</span>
              <span v-else class="material-symbols-rounded">save</span>
              Save Changes
            </button>
          </template>
        </div>
      </div>
    </div>

    <!-- Protocols Card -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
      <div class="card p-4 text-center">
        <span class="material-symbols-rounded text-3xl text-primary-500 mb-2">mail</span>
        <p class="font-semibold">IMAP</p>
        <p class="text-sm text-surface-500">Port 143</p>
      </div>
      <div class="card p-4 text-center">
        <span class="material-symbols-rounded text-3xl text-green-500 mb-2">lock</span>
        <p class="font-semibold">IMAPS</p>
        <p class="text-sm text-surface-500">Port 993</p>
      </div>
      <div class="card p-4 text-center">
        <span class="material-symbols-rounded text-3xl text-blue-500 mb-2">download</span>
        <p class="font-semibold">POP3</p>
        <p class="text-sm text-surface-500">Port 110</p>
      </div>
      <div class="card p-4 text-center">
        <span class="material-symbols-rounded text-3xl text-purple-500 mb-2">enhanced_encryption</span>
        <p class="font-semibold">POP3S</p>
        <p class="text-sm text-surface-500">Port 995</p>
      </div>
    </div>

    <!-- Loading state -->
    <div v-if="loading" class="card p-12">
      <div class="flex items-center justify-center gap-3 text-surface-500">
        <span class="material-symbols-rounded animate-spin text-2xl">progress_activity</span>
        <span>Loading Dovecot configuration...</span>
      </div>
    </div>

    <template v-else>
      <!-- Settings Grid -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
        <div 
          v-for="def in settingDefinitions" 
          :key="def.key"
          class="card p-5"
        >
          <div class="flex items-start justify-between">
            <div class="flex-1">
              <label class="block font-medium mb-1">{{ def.label }}</label>
              <p class="text-sm text-surface-500 mb-3">{{ def.description }}</p>
            </div>
            <div class="flex items-center gap-2">
              <span 
                v-if="settings[def.key] !== originalSettings[def.key]" 
                class="text-xs px-2 py-0.5 rounded-full bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400"
              >
                Modified
              </span>
            </div>
          </div>
          
          <!-- Toggle for boolean settings -->
          <template v-if="def.type === 'toggle'">
            <div class="flex items-center gap-3">
              <button
                @click="settings[def.key] = settings[def.key] === 'yes' ? 'no' : 'yes'"
                :disabled="!editMode"
                :class="[
                  'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                  settings[def.key] === 'yes' ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600',
                  !editMode && 'opacity-60 cursor-not-allowed'
                ]"
              >
                <span
                  :class="[
                    'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                    settings[def.key] === 'yes' ? 'translate-x-6' : 'translate-x-1'
                  ]"
                />
              </button>
              <span class="text-sm font-medium">
                {{ settings[def.key] || def.placeholder }}
              </span>
            </div>
          </template>
          
          <!-- Select for options -->
          <template v-else-if="def.type === 'select'">
            <select
              v-model="settings[def.key]"
              :disabled="!editMode"
              class="input w-full"
              :class="!editMode && 'bg-surface-50 dark:bg-surface-800'"
            >
              <option v-for="opt in def.options" :key="opt" :value="opt">{{ opt }}</option>
            </select>
          </template>
          
          <!-- Input for other settings -->
          <template v-else>
            <input
              v-model="settings[def.key]"
              :disabled="!editMode"
              :placeholder="def.placeholder"
              class="input w-full"
              :class="!editMode && 'bg-surface-50 dark:bg-surface-800'"
            />
          </template>
        </div>
      </div>

      <!-- Active Connections -->
      <div v-if="connections.length > 0" class="card p-6 mb-6">
        <h3 class="font-semibold mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded text-green-500">group</span>
          Active Connections ({{ connections.length }})
        </h3>

        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="text-left border-b border-surface-200 dark:border-surface-700">
                <th class="pb-3 font-medium">User</th>
                <th class="pb-3 font-medium">Protocol</th>
                <th class="pb-3 font-medium">IP Address</th>
                <th class="pb-3 font-medium">Connected</th>
              </tr>
            </thead>
            <tbody class="text-sm">
              <tr 
                v-for="conn in connections.slice(0, 20)" 
                :key="conn.id"
                class="border-b border-surface-100 dark:border-surface-800"
              >
                <td class="py-2">{{ conn.user }}</td>
                <td class="py-2">
                  <span class="px-2 py-0.5 text-xs rounded-full bg-primary-100 dark:bg-primary-500/20 text-primary-600">
                    {{ conn.protocol }}
                  </span>
                </td>
                <td class="py-2 font-mono text-xs">{{ conn.ip }}</td>
                <td class="py-2 text-surface-500">{{ conn.connected }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <p v-if="connections.length > 20" class="mt-4 text-sm text-surface-500">
          Showing first 20 of {{ connections.length }} connections.
        </p>
      </div>
    </template>

    <!-- Info Card -->
    <div class="card p-6 mt-6 bg-blue-50 dark:bg-blue-500/10 border-blue-200 dark:border-blue-500/20">
      <div class="flex gap-4">
        <span class="material-symbols-rounded text-blue-500 text-xl">info</span>
        <div>
          <h4 class="font-medium text-blue-700 dark:text-blue-400 mb-1">About Dovecot</h4>
          <ul class="text-sm text-blue-600 dark:text-blue-300 space-y-1">
            <li>Dovecot handles IMAP and POP3 for email clients to retrieve mail.</li>
            <li>SSL/TLS settings affect connection security.</li>
            <li>Debug logging can help troubleshoot authentication issues.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>

