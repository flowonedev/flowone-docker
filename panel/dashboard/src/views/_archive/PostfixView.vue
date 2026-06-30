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
const queue = ref([])

// Common Postfix settings to display/edit
const settingDefinitions = [
  { key: 'myhostname', label: 'Hostname', description: 'Mail server hostname (FQDN)', placeholder: 'mail.example.com' },
  { key: 'mydomain', label: 'Domain', description: 'Mail domain', placeholder: 'example.com' },
  { key: 'message_size_limit', label: 'Message Size Limit', description: 'Maximum email size in bytes (0 = unlimited)', placeholder: '52428800' },
  { key: 'mailbox_size_limit', label: 'Mailbox Size Limit', description: 'Maximum mailbox size in bytes (0 = unlimited)', placeholder: '0' },
  { key: 'smtpd_recipient_limit', label: 'Recipient Limit', description: 'Maximum recipients per message', placeholder: '100' },
  { key: 'maximal_queue_lifetime', label: 'Max Queue Lifetime', description: 'How long to keep undeliverable mail', placeholder: '5d' },
  { key: 'bounce_queue_lifetime', label: 'Bounce Queue Lifetime', description: 'How long to keep bounce messages', placeholder: '5d' },
  { key: 'smtp_tls_security_level', label: 'TLS Security Level', description: 'Outbound TLS security level', placeholder: 'may', type: 'select', options: ['none', 'may', 'encrypt', 'dane', 'verify', 'secure'] },
  { key: 'smtpd_tls_security_level', label: 'SMTPD TLS Level', description: 'Inbound TLS security level', placeholder: 'may', type: 'select', options: ['none', 'may', 'encrypt'] },
  { key: 'smtpd_use_tls', label: 'Use TLS', description: 'Enable TLS for incoming connections', placeholder: 'yes', type: 'toggle' },
]

const hasChanges = computed(() => {
  return JSON.stringify(settings.value) !== JSON.stringify(originalSettings.value)
})

const formatBytes = (bytes) => {
  if (bytes === 0) return 'Unlimited'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

const fetchStatus = async () => {
  try {
    const response = await api.get('/postfix/status')
    if (response.data.success) {
      status.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch Postfix status', e)
  }
}

const fetchSettings = async () => {
  loading.value = true
  try {
    const response = await api.get('/postfix/settings')
    if (response.data.success) {
      settings.value = response.data.data.settings || {}
      originalSettings.value = { ...settings.value }
      queue.value = response.data.data.queue || []
    }
  } catch (e) {
    toast.error('Failed to load Postfix settings')
  } finally {
    loading.value = false
  }
}

const saveSettings = async () => {
  saving.value = true
  try {
    const response = await api.put('/postfix/settings', {
      settings: settings.value
    })
    if (response.data.success) {
      toast.success('Postfix settings saved successfully')
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

const restartPostfix = async () => {
  saving.value = true
  try {
    const response = await api.post('/postfix/restart')
    if (response.data.success) {
      toast.success('Postfix restarted successfully')
      await fetchStatus()
    } else {
      toast.error(response.data.error || 'Failed to restart Postfix')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restart Postfix')
  } finally {
    saving.value = false
  }
}

const flushQueue = async () => {
  saving.value = true
  try {
    const response = await api.post('/postfix/flush')
    if (response.data.success) {
      toast.success('Mail queue flushed')
      await fetchSettings()
    } else {
      toast.error(response.data.error || 'Failed to flush queue')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to flush queue')
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
        <h1 class="page-title">Postfix Configuration</h1>
        <p class="page-subtitle">Manage SMTP mail server settings</p>
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
            Queue: <span class="font-mono">{{ queue.length }} messages</span>
          </div>
        </div>
        
        <div class="flex items-center gap-3">
          <button 
            @click="flushQueue"
            class="btn btn-secondary"
            :disabled="saving || queue.length === 0"
          >
            <span class="material-symbols-rounded">outbox</span>
            Flush Queue
          </button>
          
          <button 
            @click="restartPostfix"
            class="btn btn-secondary"
            :disabled="saving"
          >
            <span class="material-symbols-rounded">refresh</span>
            Restart Postfix
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

    <!-- Loading state -->
    <div v-if="loading" class="card p-12">
      <div class="flex items-center justify-center gap-3 text-surface-500">
        <span class="material-symbols-rounded animate-spin text-2xl">progress_activity</span>
        <span>Loading Postfix configuration...</span>
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

      <!-- Queue Table -->
      <div v-if="queue.length > 0" class="card p-6 mb-6">
        <h3 class="font-semibold mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded text-amber-500">schedule_send</span>
          Mail Queue ({{ queue.length }} messages)
        </h3>

        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="text-left border-b border-surface-200 dark:border-surface-700">
                <th class="pb-3 font-medium">ID</th>
                <th class="pb-3 font-medium">From</th>
                <th class="pb-3 font-medium">To</th>
                <th class="pb-3 font-medium">Size</th>
                <th class="pb-3 font-medium">Status</th>
              </tr>
            </thead>
            <tbody class="text-sm">
              <tr 
                v-for="msg in queue.slice(0, 20)" 
                :key="msg.id"
                class="border-b border-surface-100 dark:border-surface-800"
              >
                <td class="py-2 font-mono text-xs">{{ msg.id }}</td>
                <td class="py-2">{{ msg.from }}</td>
                <td class="py-2">{{ msg.to }}</td>
                <td class="py-2">{{ formatBytes(msg.size) }}</td>
                <td class="py-2">
                  <span :class="[
                    'px-2 py-0.5 text-xs rounded-full',
                    msg.status === 'active' ? 'bg-green-100 dark:bg-green-500/20 text-green-600' : 'bg-amber-100 dark:bg-amber-500/20 text-amber-600'
                  ]">
                    {{ msg.status }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <p v-if="queue.length > 20" class="mt-4 text-sm text-surface-500">
          Showing first 20 of {{ queue.length }} messages.
        </p>
      </div>
    </template>

    <!-- Info Card -->
    <div class="card p-6 mt-6 bg-blue-50 dark:bg-blue-500/10 border-blue-200 dark:border-blue-500/20">
      <div class="flex gap-4">
        <span class="material-symbols-rounded text-blue-500 text-xl">info</span>
        <div>
          <h4 class="font-medium text-blue-700 dark:text-blue-400 mb-1">About Postfix</h4>
          <ul class="text-sm text-blue-600 dark:text-blue-300 space-y-1">
            <li>Postfix is the SMTP server responsible for sending and receiving emails.</li>
            <li>Changes require a restart to take effect.</li>
            <li>TLS settings affect email security and deliverability.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>

