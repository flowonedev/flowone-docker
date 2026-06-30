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
const variables = ref([])
const searchQuery = ref('')

// Common MySQL settings to display/edit
const settingDefinitions = [
  { key: 'max_connections', label: 'Max Connections', description: 'Maximum simultaneous client connections', placeholder: '151' },
  { key: 'max_allowed_packet', label: 'Max Allowed Packet', description: 'Maximum size of one packet or generated/intermediate string', placeholder: '64M' },
  { key: 'innodb_buffer_pool_size', label: 'InnoDB Buffer Pool Size', description: 'Memory for caching data and indexes (70-80% of RAM for dedicated servers)', placeholder: '128M' },
  { key: 'innodb_log_file_size', label: 'InnoDB Log File Size', description: 'Size of each InnoDB redo log file', placeholder: '48M' },
  { key: 'query_cache_size', label: 'Query Cache Size', description: 'Memory for caching query results (0 to disable)', placeholder: '0' },
  { key: 'tmp_table_size', label: 'Tmp Table Size', description: 'Maximum size of internal in-memory temp tables', placeholder: '16M' },
  { key: 'max_heap_table_size', label: 'Max Heap Table Size', description: 'Maximum size for MEMORY tables', placeholder: '16M' },
  { key: 'slow_query_log', label: 'Slow Query Log', description: 'Enable logging of slow queries', placeholder: 'OFF', type: 'toggle' },
  { key: 'long_query_time', label: 'Long Query Time', description: 'Queries longer than this (seconds) are logged', placeholder: '10' },
  { key: 'wait_timeout', label: 'Wait Timeout', description: 'Seconds to wait for activity on a connection', placeholder: '28800' },
]

const hasChanges = computed(() => {
  return JSON.stringify(settings.value) !== JSON.stringify(originalSettings.value)
})

const filteredVariables = computed(() => {
  if (!searchQuery.value) return variables.value.slice(0, 50)
  const q = searchQuery.value.toLowerCase()
  return variables.value.filter(v => 
    v.name.toLowerCase().includes(q) || 
    v.value.toString().toLowerCase().includes(q)
  ).slice(0, 50)
})

const fetchStatus = async () => {
  try {
    const response = await api.get('/mysql/status')
    if (response.data.success) {
      status.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch MySQL status', e)
  }
}

const fetchSettings = async () => {
  loading.value = true
  try {
    const response = await api.get('/mysql/settings')
    if (response.data.success) {
      settings.value = response.data.data.settings || {}
      originalSettings.value = { ...settings.value }
      variables.value = response.data.data.variables || []
    }
  } catch (e) {
    toast.error('Failed to load MySQL settings')
  } finally {
    loading.value = false
  }
}

const saveSettings = async () => {
  saving.value = true
  try {
    const response = await api.put('/mysql/settings', {
      settings: settings.value
    })
    if (response.data.success) {
      toast.success('MySQL settings saved successfully')
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

const restartMysql = async () => {
  saving.value = true
  try {
    const response = await api.post('/mysql/restart')
    if (response.data.success) {
      toast.success('MySQL restarted successfully')
      await fetchStatus()
    } else {
      toast.error(response.data.error || 'Failed to restart MySQL')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restart MySQL')
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
        <h1 class="page-title">MySQL Configuration</h1>
        <p class="page-subtitle">Manage global MySQL/MariaDB server settings</p>
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
          
          <div v-if="status?.uptime" class="text-surface-500">
            Uptime: <span class="font-mono">{{ status.uptime }}</span>
          </div>
        </div>
        
        <div class="flex items-center gap-3">
          <button 
            @click="restartMysql"
            class="btn btn-secondary"
            :disabled="saving"
          >
            <span class="material-symbols-rounded">refresh</span>
            Restart MySQL
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
        <span>Loading MySQL configuration...</span>
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
                @click="settings[def.key] = settings[def.key] === 'ON' ? 'OFF' : 'ON'"
                :disabled="!editMode"
                :class="[
                  'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                  settings[def.key] === 'ON' ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600',
                  !editMode && 'opacity-60 cursor-not-allowed'
                ]"
              >
                <span
                  :class="[
                    'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                    settings[def.key] === 'ON' ? 'translate-x-6' : 'translate-x-1'
                  ]"
                />
              </button>
              <span class="text-sm font-medium">
                {{ settings[def.key] || def.placeholder }}
              </span>
            </div>
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

      <!-- All Variables -->
      <div class="card p-6">
        <div class="flex items-center justify-between mb-4">
          <h3 class="font-semibold flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">data_object</span>
            All Server Variables
          </h3>
          <div class="relative">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
            <input 
              v-model="searchQuery"
              type="text" 
              placeholder="Search variables..."
              class="input pl-10 w-64"
            />
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="text-left border-b border-surface-200 dark:border-surface-700">
                <th class="pb-3 font-medium">Variable</th>
                <th class="pb-3 font-medium">Value</th>
              </tr>
            </thead>
            <tbody class="font-mono text-sm">
              <tr 
                v-for="v in filteredVariables" 
                :key="v.name"
                class="border-b border-surface-100 dark:border-surface-800"
              >
                <td class="py-2 text-surface-600 dark:text-surface-400">{{ v.name }}</td>
                <td class="py-2">{{ v.value }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <p v-if="variables.length > 50 && !searchQuery" class="mt-4 text-sm text-surface-500">
          Showing first 50 variables. Use search to find specific variables.
        </p>
      </div>
    </template>

    <!-- Info Card -->
    <div class="card p-6 mt-6 bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/20">
      <div class="flex gap-4">
        <span class="material-symbols-rounded text-amber-500 text-xl">warning</span>
        <div>
          <h4 class="font-medium text-amber-700 dark:text-amber-400 mb-1">Warning</h4>
          <ul class="text-sm text-amber-600 dark:text-amber-300 space-y-1">
            <li>Changing MySQL settings can affect all databases and applications.</li>
            <li>Incorrect settings may cause MySQL to fail to start.</li>
            <li>Always backup your configuration before making changes.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>

