<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import ConfirmModal from '@/components/ConfirmModal.vue'

const toast = useToastStore()

const loading = ref(true)
const saving = ref(false)
const phpVersions = ref([])
const selectedVersion = ref(null)
const settings = ref({})
const originalSettings = ref({})
const editMode = ref(false)

// Common PHP settings to display/edit
const settingDefinitions = [
  { key: 'memory_limit', label: 'Memory Limit', description: 'Maximum memory a script can consume', placeholder: '256M' },
  { key: 'max_execution_time', label: 'Max Execution Time', description: 'Maximum time a script can run (seconds)', placeholder: '30' },
  { key: 'max_input_time', label: 'Max Input Time', description: 'Maximum time to parse input data (seconds)', placeholder: '60' },
  { key: 'upload_max_filesize', label: 'Upload Max Filesize', description: 'Maximum size of uploaded files', placeholder: '64M' },
  { key: 'post_max_size', label: 'Post Max Size', description: 'Maximum size of POST data', placeholder: '64M' },
  { key: 'max_input_vars', label: 'Max Input Vars', description: 'Maximum number of input variables', placeholder: '1000' },
  { key: 'max_file_uploads', label: 'Max File Uploads', description: 'Maximum number of files uploaded at once', placeholder: '20' },
  { key: 'display_errors', label: 'Display Errors', description: 'Show errors on screen (dev only)', placeholder: 'Off', type: 'toggle' },
  { key: 'error_reporting', label: 'Error Reporting', description: 'Which errors to report', placeholder: 'E_ALL & ~E_DEPRECATED' },
  { key: 'date.timezone', label: 'Timezone', description: 'Default timezone', placeholder: 'UTC' },
]

const hasChanges = computed(() => {
  return JSON.stringify(settings.value) !== JSON.stringify(originalSettings.value)
})

const fetchPhpVersions = async () => {
  loading.value = true
  try {
    const response = await api.get('/php/versions')
    if (response.data.success) {
      phpVersions.value = response.data.data.versions || []
      if (phpVersions.value.length > 0 && !selectedVersion.value) {
        selectedVersion.value = phpVersions.value[0].version
      }
    }
  } catch (e) {
    toast.error('Failed to load PHP versions')
  } finally {
    loading.value = false
  }
}

const fetchSettings = async () => {
  if (!selectedVersion.value) return
  
  loading.value = true
  try {
    const response = await api.get(`/php/${selectedVersion.value}/settings`)
    if (response.data.success) {
      settings.value = response.data.data.settings || {}
      originalSettings.value = { ...settings.value }
    }
  } catch (e) {
    toast.error('Failed to load PHP settings')
  } finally {
    loading.value = false
  }
}

const saveSettings = async () => {
  saving.value = true
  try {
    const response = await api.put(`/php/${selectedVersion.value}/settings`, {
      settings: settings.value
    })
    if (response.data.success) {
      toast.success('PHP settings saved successfully')
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

const restartPhp = async () => {
  saving.value = true
  try {
    const response = await api.post(`/php/${selectedVersion.value}/restart`)
    if (response.data.success) {
      toast.success(`PHP ${selectedVersion.value} restarted successfully`)
    } else {
      toast.error(response.data.error || 'Failed to restart PHP')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to restart PHP')
  } finally {
    saving.value = false
  }
}

watch(selectedVersion, () => {
  if (selectedVersion.value) {
    fetchSettings()
  }
})

onMounted(() => {
  fetchPhpVersions()
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">PHP Configuration</h1>
        <p class="page-subtitle">Manage global PHP settings for each version</p>
      </div>
    </div>

    <!-- Version Selector -->
    <div class="card p-6 mb-6">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
          <label class="font-medium">PHP Version:</label>
          <select 
            v-model="selectedVersion" 
            class="input w-48"
            :disabled="loading"
          >
            <option v-for="php in phpVersions" :key="php.version" :value="php.version">
              PHP {{ php.version }} {{ php.active ? '(Active)' : '' }}
            </option>
          </select>
        </div>
        
        <div class="flex items-center gap-3">
          <button 
            @click="restartPhp"
            class="btn btn-secondary"
            :disabled="saving || !selectedVersion"
          >
            <span class="material-symbols-rounded">refresh</span>
            Restart PHP-FPM
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
        <span>Loading PHP configuration...</span>
      </div>
    </div>

    <!-- Settings Grid -->
    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
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
              @click="settings[def.key] = settings[def.key] === 'On' ? 'Off' : 'On'"
              :disabled="!editMode"
              :class="[
                'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                settings[def.key] === 'On' ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600',
                !editMode && 'opacity-60 cursor-not-allowed'
              ]"
            >
              <span
                :class="[
                  'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                  settings[def.key] === 'On' ? 'translate-x-6' : 'translate-x-1'
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

    <!-- Info Card -->
    <div class="card p-6 mt-6 bg-blue-50 dark:bg-blue-500/10 border-blue-200 dark:border-blue-500/20">
      <div class="flex gap-4">
        <span class="material-symbols-rounded text-blue-500 text-xl">info</span>
        <div>
          <h4 class="font-medium text-blue-700 dark:text-blue-400 mb-1">Important Notes</h4>
          <ul class="text-sm text-blue-600 dark:text-blue-300 space-y-1">
            <li>Changes to PHP configuration will affect all sites using this PHP version.</li>
            <li>After saving, you may need to restart PHP-FPM for changes to take effect.</li>
            <li>Some settings may be overridden at the site level via .htaccess or per-vhost config.</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>

