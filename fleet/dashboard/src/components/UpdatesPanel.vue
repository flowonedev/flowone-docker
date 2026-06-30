<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import api from '../services/api'
import { useToastStore } from '../stores/toast'

const props = defineProps({
  serverId: { type: [Number, String], required: true },
})

const toast = useToastStore()

const loading = ref(true)
const updates = ref(null) // { os_pending, npm_pending, reboot_required, checked_at, report }
const showAllOs = ref(false)
const activeTask = ref(null) // { id, scope, status, progress }
let pollTimer = null

const OS_PREVIEW_COUNT = 8

const osPackages = computed(() => updates.value?.report?.os?.packages || [])
const npmApps = computed(() => updates.value?.report?.npm || [])
const totalPending = computed(
  () => (updates.value?.os_pending || 0) + (updates.value?.npm_pending || 0)
)
const visibleOsPackages = computed(() =>
  showAllOs.value ? osPackages.value : osPackages.value.slice(0, OS_PREVIEW_COUNT)
)
const busy = computed(() => !!activeTask.value)

const checkedAgo = computed(() => {
  if (!updates.value?.checked_at) return null
  // MySQL "YYYY-MM-DD HH:MM:SS" (UTC) -> ISO for cross-browser Date parsing
  const iso = updates.value.checked_at.replace(' ', 'T') + 'Z'
  const diffMin = Math.floor((Date.now() - new Date(iso).getTime()) / 60000)
  if (diffMin < 1) return 'just now'
  if (diffMin < 60) return `${diffMin}m ago`
  const h = Math.floor(diffMin / 60)
  return h < 24 ? `${h}h ago` : `${Math.floor(h / 24)}d ago`
})

const fetchUpdates = async () => {
  try {
    const response = await api.get(`/api/servers/${props.serverId}/updates`)
    updates.value = response.data
  } catch (error) {
    // Older API without migration 025 - leave panel in "no report" state
    updates.value = null
  } finally {
    loading.value = false
  }
}

const applyUpdates = async (scope) => {
  if (busy.value) return
  try {
    const response = await api.post(`/api/servers/${props.serverId}/updates/apply`, { scope })
    activeTask.value = { id: response.data.id, scope, status: 'pending', progress: 0 }
    toast.success(scope === 'check' ? 'Update check queued' : 'Update task queued')
    pollTimer = setInterval(pollTask, 4000)
  } catch (error) {
    toast.error(error.response?.data?.error || error.message || 'Failed to queue update task')
  }
}

const pollTask = async () => {
  if (!activeTask.value) return
  try {
    const response = await api.get(`/api/servers/${props.serverId}/tasks/${activeTask.value.id}`)
    const task = response.data
    activeTask.value.status = task.status
    activeTask.value.progress = task.progress

    if (['success', 'failed', 'cancelled'].includes(task.status)) {
      stopPolling()
      reportTaskOutcome(task)
      activeTask.value = null
      await fetchUpdates()
    }
  } catch (error) {
    // transient poll error - keep trying until task resolves
  }
}

const reportTaskOutcome = (task) => {
  if (task.status !== 'success') {
    toast.error(task.error_message || 'Update task failed - check task logs')
    return
  }
  let result = null
  try {
    result = task.result ? JSON.parse(task.result) : null
  } catch (e) { /* non-JSON result */ }

  if (result?.scope === 'check') {
    toast.success('Update check finished')
    return
  }
  const restarted = (result?.restarted_services || [])
    .filter((s) => s.success)
    .map((s) => s.service)
  let msg = 'Updates applied'
  if (restarted.length) msg += ` - restarted: ${restarted.join(', ')}`
  toast.success(msg)
  if (result?.reboot_required) {
    toast.warning('The OS recommends a reboot. Fleet never reboots automatically - schedule it manually.')
  }
}

const stopPolling = () => {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

const taskLabel = computed(() => {
  if (!activeTask.value) return ''
  const verb = activeTask.value.scope === 'check' ? 'Checking for updates' : 'Applying updates'
  return activeTask.value.status === 'running' ? `${verb}...` : `${verb} (queued, waiting for agent)...`
})

onMounted(fetchUpdates)
onUnmounted(stopPolling)
</script>

<template>
  <div class="card">
    <div class="card-header flex items-center justify-between">
      <h2 class="font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
        <span class="material-symbols-rounded text-amber-500">system_update_alt</span>
        Updates
        <span v-if="!loading && updates" :class="['badge', totalPending > 0 ? 'badge-warning' : 'badge-success']">
          {{ totalPending > 0 ? `${totalPending} pending` : 'up to date' }}
        </span>
      </h2>
      <div class="flex items-center gap-2">
        <span v-if="checkedAgo" class="text-xs text-surface-400 dark:text-surface-500">checked {{ checkedAgo }}</span>
        <button
          @click="applyUpdates('check')"
          :disabled="busy"
          class="btn btn-ghost btn-xs"
          title="Re-scan packages on the server now"
        >
          <span class="material-symbols-rounded" :class="{ 'animate-spin': busy && activeTask?.scope === 'check' }">refresh</span>
        </button>
      </div>
    </div>

    <div class="card-body">
      <!-- Loading -->
      <div v-if="loading" class="text-center py-6">
        <div class="spinner w-6 h-6 mx-auto mb-2"></div>
        <p class="text-sm text-surface-500 dark:text-surface-400">Loading update report...</p>
      </div>

      <!-- No report yet -->
      <div v-else-if="!updates" class="text-center py-6 text-surface-500 dark:text-surface-400 text-sm">
        <span class="material-symbols-rounded text-3xl block mb-2">pending</span>
        No update report yet. The agent scans hourly - or trigger a check now.
        <div class="mt-3">
          <button @click="applyUpdates('check')" :disabled="busy" class="btn btn-secondary btn-sm">
            <span class="material-symbols-rounded text-base mr-1">search</span>
            Check now
          </button>
        </div>
      </div>

      <template v-else>
        <!-- Running task banner -->
        <div
          v-if="activeTask"
          class="mb-4 flex items-center gap-3 p-3 rounded-xl bg-primary-50 dark:bg-primary-900/20 border border-primary-200 dark:border-primary-800"
        >
          <div class="spinner spinner-sm"></div>
          <div class="flex-1">
            <p class="text-sm font-medium text-primary-700 dark:text-primary-300">{{ taskLabel }}</p>
            <p class="text-xs text-surface-500 dark:text-surface-400">
              Services restart automatically when needed. The server itself is never rebooted.
            </p>
          </div>
        </div>

        <!-- Reboot recommended note -->
        <div
          v-if="updates.reboot_required"
          class="mb-4 flex items-start gap-2 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800"
        >
          <span class="material-symbols-rounded text-amber-500 text-xl">warning</span>
          <p class="text-xs text-amber-700 dark:text-amber-300">
            The OS recommends a reboot to finish previous updates. Fleet never reboots servers automatically - schedule it manually.
          </p>
        </div>

        <!-- All up to date -->
        <div v-if="totalPending === 0" class="text-center py-4 text-surface-500 dark:text-surface-400 text-sm">
          <span class="material-symbols-rounded text-3xl block mb-2 text-primary-500">verified</span>
          All system and npm packages are up to date.
        </div>

        <!-- OS packages -->
        <div v-if="osPackages.length" class="mb-5">
          <div class="flex items-center justify-between mb-2">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400">
              System packages ({{ updates.os_pending }})
              <span v-if="updates.report?.os?.manager" class="normal-case font-normal">via {{ updates.report.os.manager }}</span>
            </h3>
            <button
              @click="applyUpdates('system')"
              :disabled="busy"
              class="btn btn-secondary btn-xs"
            >
              <span class="material-symbols-rounded text-base mr-1">upgrade</span>
              Update system
            </button>
          </div>
          <div class="rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] divide-y divide-surface-100 dark:divide-[rgb(var(--color-border))] overflow-hidden">
            <div
              v-for="pkg in visibleOsPackages"
              :key="pkg.name"
              class="flex items-center justify-between px-3 py-1.5 text-sm"
            >
              <span class="font-mono text-surface-700 dark:text-surface-300 truncate">{{ pkg.name }}</span>
              <span class="text-xs text-surface-400 dark:text-surface-500 whitespace-nowrap ml-3">
                <template v-if="pkg.current">{{ pkg.current }} &rarr; </template>{{ pkg.available }}
              </span>
            </div>
          </div>
          <button
            v-if="osPackages.length > OS_PREVIEW_COUNT"
            @click="showAllOs = !showAllOs"
            class="mt-2 text-xs text-primary-600 dark:text-primary-400 hover:underline"
          >
            {{ showAllOs ? 'Show less' : `Show all ${osPackages.length} packages` }}
          </button>
        </div>

        <!-- npm apps -->
        <div v-if="npmApps.length" class="mb-2">
          <div class="flex items-center justify-between mb-2">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-surface-500 dark:text-surface-400">
              npm packages ({{ updates.npm_pending }})
            </h3>
            <button
              @click="applyUpdates('npm')"
              :disabled="busy"
              class="btn btn-secondary btn-xs"
            >
              <span class="material-symbols-rounded text-base mr-1">upgrade</span>
              Update npm
            </button>
          </div>
          <div
            v-for="app in npmApps"
            :key="app.dir"
            class="mb-3 rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] overflow-hidden"
          >
            <div class="px-3 py-2 bg-surface-50 dark:bg-surface-800/50 flex items-center justify-between">
              <span class="text-sm font-medium text-surface-700 dark:text-surface-300">{{ app.service }}</span>
              <span class="text-xs text-surface-400 dark:text-surface-500 font-mono truncate ml-3">{{ app.dir }}</span>
            </div>
            <div class="divide-y divide-surface-100 dark:divide-[rgb(var(--color-border))]">
              <div
                v-for="pkg in app.packages"
                :key="pkg.name"
                class="flex items-center justify-between px-3 py-1.5 text-sm"
              >
                <span class="font-mono text-surface-700 dark:text-surface-300 truncate">{{ pkg.name }}</span>
                <span class="text-xs text-surface-400 dark:text-surface-500 whitespace-nowrap ml-3">
                  {{ pkg.current }} &rarr; {{ pkg.wanted }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Update all -->
        <div v-if="totalPending > 0" class="pt-3 border-t border-surface-100 dark:border-[rgb(var(--color-border))] flex items-center justify-between">
          <p class="text-xs text-surface-400 dark:text-surface-500">
            Affected services restart automatically. The server is never rebooted.
          </p>
          <button @click="applyUpdates('all')" :disabled="busy" class="btn btn-primary btn-sm">
            <span class="material-symbols-rounded text-base mr-1">system_update_alt</span>
            Update everything
          </button>
        </div>
      </template>
    </div>
  </div>
</template>
