<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'

const toast = useToastStore()

const loading = ref(true)
const jobs = ref([])
const logs = ref([])
const showLogs = ref(false)
const logsLoading = ref(false)
const createModal = ref(false)
const editModal = ref({ show: false, job: null })
const deleteModal = ref({ show: false, job: null })
const submitting = ref(false)
const searchQuery = ref('')
const filterSource = ref('all')

// Schedule presets
const schedulePresets = [
  { label: 'Every minute', value: '* * * * *' },
  { label: 'Every 5 minutes', value: '*/5 * * * *' },
  { label: 'Every 15 minutes', value: '*/15 * * * *' },
  { label: 'Every 30 minutes', value: '*/30 * * * *' },
  { label: 'Every hour', value: '0 * * * *' },
  { label: 'Every 6 hours', value: '0 */6 * * *' },
  { label: 'Every 12 hours', value: '0 */12 * * *' },
  { label: 'Daily at midnight', value: '0 0 * * *' },
  { label: 'Daily at 3 AM', value: '0 3 * * *' },
  { label: 'Weekly (Sunday)', value: '0 0 * * 0' },
  { label: 'Monthly (1st)', value: '0 0 1 * *' },
  { label: 'At system startup', value: '@reboot' },
]

const newJob = ref({
  name: '',
  schedule: '0 * * * *',
  command: '',
  description: '',
  user: 'root',
})

const editedJob = ref({
  id: '',
  schedule: '',
  command: '',
  user: '',
})

// Filter jobs
const filteredJobs = computed(() => {
  let result = [...jobs.value]
  
  // Filter by source
  if (filterSource.value !== 'all') {
    result = result.filter(j => j.source === filterSource.value)
  }
  
  // Search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(j => 
      j.command?.toLowerCase().includes(query) ||
      j.schedule?.toLowerCase().includes(query) ||
      j.filename?.toLowerCase().includes(query) ||
      j.schedule_human?.toLowerCase().includes(query)
    )
  }
  
  return result
})

const jobsBySource = computed(() => {
  const sources = {}
  for (const job of jobs.value) {
    if (!sources[job.source]) sources[job.source] = 0
    sources[job.source]++
  }
  return sources
})

const fetchJobs = async () => {
  try {
    const response = await api.get('/cron')
    if (response.data.success) {
      jobs.value = response.data.data.jobs || []
    }
  } catch (e) {
    toast.error('Failed to load cron jobs')
  }
}

const fetchLogs = async () => {
  logsLoading.value = true
  try {
    const response = await api.get('/cron/logs', { params: { lines: 100 } })
    if (response.data.success) {
      logs.value = response.data.data.logs || []
    }
  } catch (e) {
    toast.error('Failed to load cron logs')
  } finally {
    logsLoading.value = false
  }
}

const createJob = async () => {
  submitting.value = true
  try {
    const response = await api.post('/cron', newJob.value)
    
    if (response.data.success) {
      toast.success('Cron job created')
      createModal.value = false
      resetNewJob()
      await fetchJobs()
    } else {
      toast.error(response.data.error || 'Failed to create cron job')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create cron job')
  } finally {
    submitting.value = false
  }
}

const updateJob = async () => {
  submitting.value = true
  try {
    const response = await api.put(`/cron/${encodeURIComponent(editedJob.value.id)}`, {
      schedule: editedJob.value.schedule,
      command: editedJob.value.command,
      user: editedJob.value.user,
    })
    
    if (response.data.success) {
      toast.success('Cron job updated')
      editModal.value = { show: false, job: null }
      await fetchJobs()
    } else {
      toast.error(response.data.error || 'Failed to update cron job')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update cron job')
  } finally {
    submitting.value = false
  }
}

const deleteJob = async () => {
  if (!deleteModal.value.job) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/cron/${encodeURIComponent(deleteModal.value.job.id)}`)
    
    if (response.data.success) {
      toast.success('Cron job deleted')
      deleteModal.value = { show: false, job: null }
      await fetchJobs()
    } else {
      toast.error(response.data.error || 'Failed to delete cron job')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete cron job')
  } finally {
    submitting.value = false
  }
}

const toggleJob = async (job) => {
  try {
    const response = await api.post(`/cron/${encodeURIComponent(job.id)}/toggle`)
    
    if (response.data.success) {
      toast.success(response.data.data.enabled ? 'Cron job enabled' : 'Cron job disabled')
      await fetchJobs()
    } else {
      toast.error(response.data.error || 'Failed to toggle cron job')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to toggle cron job')
  }
}

const openEditModal = (job) => {
  editedJob.value = {
    id: job.id,
    schedule: job.schedule,
    command: job.command,
    user: job.user,
  }
  editModal.value = { show: true, job }
}

const resetNewJob = () => {
  newJob.value = {
    name: '',
    schedule: '0 * * * *',
    command: '',
    description: '',
    user: 'root',
  }
}

const applyPreset = (preset, target) => {
  if (target === 'new') {
    newJob.value.schedule = preset.value
  } else {
    editedJob.value.schedule = preset.value
  }
}

const getSourceLabel = (source) => {
  const labels = {
    crond: '/etc/cron.d',
    crontab: 'Crontab',
    system: 'System',
  }
  return labels[source] || source
}

const getSourceColor = (source) => {
  const colors = {
    crond: 'bg-blue-100 dark:bg-blue-500/20 text-blue-600',
    crontab: 'bg-green-100 dark:bg-green-500/20 text-green-600',
    system: 'bg-purple-100 dark:bg-purple-500/20 text-purple-600',
  }
  return colors[source] || 'bg-surface-100 dark:bg-surface-700 text-surface-600'
}

onMounted(async () => {
  await fetchJobs()
  loading.value = false
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Cron Jobs</h1>
        <p class="text-surface-500 text-sm mt-1">Manage scheduled tasks</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button @click="showLogs = true; fetchLogs()" class="btn-secondary">
          <span class="material-symbols-rounded">receipt_long</span>
          <span class="hidden sm:inline">Logs</span>
        </button>
        <button @click="createModal = true" class="btn-primary">
          <span class="material-symbols-rounded">add</span>
          <span class="hidden sm:inline">New Job</span>
        </button>
      </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 mb-6">
      <div class="stat-card">
        <div class="flex items-center gap-3">
          <span class="material-symbols-rounded text-surface-400">schedule</span>
          <span class="font-medium">Total Jobs</span>
        </div>
        <div class="stat-value mt-2">{{ jobs.length }}</div>
      </div>
      <div class="stat-card">
        <div class="flex items-center gap-3">
          <span class="material-symbols-rounded text-blue-500">folder</span>
          <span class="font-medium">/etc/cron.d</span>
        </div>
        <div class="stat-value mt-2">{{ jobsBySource.crond || 0 }}</div>
      </div>
      <div class="stat-card">
        <div class="flex items-center gap-3">
          <span class="material-symbols-rounded text-green-500">terminal</span>
          <span class="font-medium">Crontab</span>
        </div>
        <div class="stat-value mt-2">{{ jobsBySource.crontab || 0 }}</div>
      </div>
      <div class="stat-card">
        <div class="flex items-center gap-3">
          <span class="material-symbols-rounded text-purple-500">settings</span>
          <span class="font-medium">System</span>
        </div>
        <div class="stat-value mt-2">{{ jobsBySource.system || 0 }}</div>
      </div>
    </div>

    <!-- Filter bar -->
    <div class="card p-4 mb-6">
      <div class="flex flex-wrap gap-4 items-center">
        <div class="relative flex-1 min-w-[200px]">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
          <input
            v-model="searchQuery"
            type="text"
            class="input pl-10"
            placeholder="Search jobs..."
          />
        </div>
        
        <select v-model="filterSource" class="input w-auto">
          <option value="all">All Sources</option>
          <option value="crond">/etc/cron.d</option>
          <option value="crontab">Crontab</option>
          <option value="system">System</option>
        </select>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner"></span>
    </div>

    <!-- Jobs list -->
    <div v-else class="space-y-3">
      <div 
        v-for="job in filteredJobs" 
        :key="job.id"
        class="card p-4 hover:shadow-md transition"
        :class="!job.enabled && 'opacity-60'"
      >
        <div class="flex items-start justify-between gap-4">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-2">
              <span :class="['text-xs px-2 py-0.5 rounded-full', getSourceColor(job.source)]">
                {{ getSourceLabel(job.source) }}
              </span>
              <span v-if="job.filename" class="text-xs text-surface-500 truncate">
                {{ job.filename }}
              </span>
              <span 
                v-if="!job.enabled"
                class="text-xs px-2 py-0.5 rounded-full bg-surface-200 dark:bg-surface-700 text-surface-500"
              >
                Disabled
              </span>
            </div>
            
            <div class="flex items-center gap-3 mb-2">
              <div class="flex items-center gap-2 bg-surface-100 dark:bg-surface-800 rounded-lg px-3 py-1">
                <span class="material-symbols-rounded text-sm text-primary-500">schedule</span>
                <code class="text-sm">{{ job.schedule }}</code>
              </div>
              <span class="text-sm text-surface-500">{{ job.schedule_human }}</span>
            </div>
            
            <div class="flex items-center gap-2">
              <span class="text-xs px-2 py-0.5 rounded-full bg-surface-100 dark:bg-surface-800 text-surface-600">
                {{ job.user }}
              </span>
              <code class="text-sm text-surface-600 dark:text-surface-400 truncate">{{ job.command }}</code>
            </div>
          </div>
          
          <div class="flex items-center gap-1">
            <button 
              v-if="job.editable"
              @click="toggleJob(job)"
              class="btn-ghost btn-sm"
              :title="job.enabled ? 'Disable' : 'Enable'"
            >
              <span class="material-symbols-rounded">{{ job.enabled ? 'pause' : 'play_arrow' }}</span>
            </button>
            <button 
              v-if="job.editable"
              @click="openEditModal(job)"
              class="btn-ghost btn-sm"
              title="Edit"
            >
              <span class="material-symbols-rounded">edit</span>
            </button>
            <button 
              v-if="job.editable"
              @click="deleteModal = { show: true, job }"
              class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
              title="Delete"
            >
              <span class="material-symbols-rounded">delete</span>
            </button>
          </div>
        </div>
      </div>
      
      <div v-if="!filteredJobs.length" class="card p-12 text-center text-surface-400">
        <span class="material-symbols-rounded text-4xl mb-2 block">schedule</span>
        No cron jobs found
      </div>
    </div>

    <!-- Create Modal -->
    <Modal :show="createModal" title="Create Cron Job" @close="createModal = false">
      <form @submit.prevent="createJob" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Name (optional)</label>
          <input
            v-model="newJob.name"
            type="text"
            class="input"
            placeholder="my-backup-job"
          />
          <p class="text-xs text-surface-500 mt-1">Used as filename in /etc/cron.d/</p>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Schedule</label>
          <div class="flex gap-2 mb-2">
            <input
              v-model="newJob.schedule"
              type="text"
              class="input font-mono flex-1"
              placeholder="* * * * *"
              required
            />
          </div>
          <div class="flex flex-wrap gap-2">
            <button 
              v-for="preset in schedulePresets"
              :key="preset.value"
              type="button"
              @click="applyPreset(preset, 'new')"
              class="text-xs px-2 py-1 rounded-full bg-surface-100 dark:bg-surface-800 hover:bg-primary-100 dark:hover:bg-primary-500/20 text-surface-600 hover:text-primary-600 transition"
            >
              {{ preset.label }}
            </button>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Command</label>
          <textarea
            v-model="newJob.command"
            class="input font-mono"
            rows="2"
            placeholder="/usr/bin/php /var/www/app/artisan schedule:run"
            required
          ></textarea>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Description (optional)</label>
          <input
            v-model="newJob.description"
            type="text"
            class="input"
            placeholder="Run Laravel scheduler"
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Run as User</label>
          <input
            v-model="newJob.user"
            type="text"
            class="input"
            placeholder="root"
          />
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="createModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Create Job
          </button>
        </div>
      </form>
    </Modal>

    <!-- Edit Modal -->
    <Modal :show="editModal.show" title="Edit Cron Job" @close="editModal = { show: false, job: null }">
      <form @submit.prevent="updateJob" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Schedule</label>
          <div class="flex gap-2 mb-2">
            <input
              v-model="editedJob.schedule"
              type="text"
              class="input font-mono flex-1"
              placeholder="* * * * *"
              required
            />
          </div>
          <div class="flex flex-wrap gap-2">
            <button 
              v-for="preset in schedulePresets"
              :key="preset.value"
              type="button"
              @click="applyPreset(preset, 'edit')"
              class="text-xs px-2 py-1 rounded-full bg-surface-100 dark:bg-surface-800 hover:bg-primary-100 dark:hover:bg-primary-500/20 text-surface-600 hover:text-primary-600 transition"
            >
              {{ preset.label }}
            </button>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Command</label>
          <textarea
            v-model="editedJob.command"
            class="input font-mono"
            rows="2"
            required
          ></textarea>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Run as User</label>
          <input
            v-model="editedJob.user"
            type="text"
            class="input"
          />
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="editModal = { show: false, job: null }" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Update Job
          </button>
        </div>
      </form>
    </Modal>

    <!-- Logs Modal -->
    <Modal :show="showLogs" title="Cron Execution Logs" size="lg" @close="showLogs = false">
      <div v-if="logsLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>
      
      <div v-else-if="logs.length" class="space-y-2 max-h-96 overflow-y-auto">
        <div 
          v-for="(log, i) in logs" 
          :key="i"
          class="text-xs font-mono bg-surface-50 dark:bg-surface-800 rounded p-2"
        >
          <div v-if="log.parsed.time" class="flex items-center gap-2 text-surface-500 mb-1">
            <span>{{ log.parsed.time }}</span>
            <span v-if="log.parsed.user" class="px-1 rounded bg-surface-200 dark:bg-surface-700">{{ log.parsed.user }}</span>
          </div>
          <div class="text-surface-600 dark:text-surface-400 break-all">
            {{ log.parsed.command || log.raw }}
          </div>
        </div>
      </div>
      
      <div v-else class="text-center py-8 text-surface-400">
        No cron logs found
      </div>
    </Modal>

    <!-- Delete confirmation modal -->
    <ConfirmModal
      :show="deleteModal.show"
      title="Delete Cron Job"
      :message="`Are you sure you want to delete this cron job?`"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      @confirm="deleteJob"
      @cancel="deleteModal = { show: false, job: null }"
    />
  </div>
</template>

