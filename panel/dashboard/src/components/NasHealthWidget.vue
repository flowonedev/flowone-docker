<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import api from '@/services/api'

const health = ref(null)
const history = ref([])
const loading = ref(true)
const checking = ref(false)
const showHistory = ref(false)
const error = ref('')

let pollTimer = null

const statusColor = computed(() => {
  if (!health.value) return '#6b7280'
  return {
    healthy: '#16a34a',
    degraded: '#d97706',
    down: '#dc2626',
    unknown: '#6b7280',
  }[health.value.status] || '#6b7280'
})

const statusBg = computed(() => {
  if (!health.value) return 'bg-gray-50 dark:bg-gray-800/50'
  return {
    healthy: 'bg-emerald-50 dark:bg-emerald-900/20',
    degraded: 'bg-amber-50 dark:bg-amber-900/20',
    down: 'bg-red-50 dark:bg-red-900/20',
    unknown: 'bg-gray-50 dark:bg-gray-800/50',
  }[health.value.status] || 'bg-gray-50 dark:bg-gray-800/50'
})

const statusIcon = computed(() => {
  if (!health.value) return 'help_outline'
  return {
    healthy: 'check_circle',
    degraded: 'warning',
    down: 'error',
    unknown: 'help_outline',
  }[health.value.status] || 'help_outline'
})

const statusLabel = computed(() => {
  if (!health.value) return 'Unknown'
  return {
    healthy: 'All Systems Healthy',
    degraded: 'Degraded',
    down: 'Down',
    unknown: 'Unknown',
  }[health.value.status] || 'Unknown'
})

const checksList = computed(() => {
  if (!health.value?.checks) return []
  const order = { error: 0, warning: 1, ok: 2 }
  return Object.entries(health.value.checks)
    .map(([key, val]) => ({ key, ...val }))
    .sort((a, b) => (order[a.status] ?? 9) - (order[b.status] ?? 9))
})

const timeSinceCheck = computed(() => {
  if (!health.value?.timestamp) return 'Never'
  const diff = Math.floor((Date.now() - new Date(health.value.timestamp + ' UTC').getTime()) / 1000)
  if (diff < 0) return 'Just now'
  if (diff < 60) return `${diff}s ago`
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  return `${Math.floor(diff / 86400)}d ago`
})

// Staleness: prefer the daemon's own server-side computed flag (timezone
// safe). Fall back to a client-clock heuristic only when the daemon block
// isn't present (legacy file path).
const isStale = computed(() => {
  const sd = health.value?.shared_daemon
  if (sd && typeof sd.is_stale === 'boolean') return sd.is_stale
  if (health.value?.is_stale) return true
  if (!health.value?.timestamp) return false
  const diff = Math.floor((Date.now() - new Date(health.value.timestamp + ' UTC').getTime()) / 1000)
  return diff > 120
})

// Recovery breaker snapshot, surfaced when auto-recovery is backing off or
// has been permanently blocked (operator intervention required).
const recoveryBreaker = computed(() => health.value?.auto_recovery?.breaker || null)
const recoveryPaused = computed(() => {
  const b = recoveryBreaker.value
  return !!(b && (b.permanent || b.quarantined))
})

const historyChanges = computed(() => {
  if (!history.value.length) return []
  const changes = []
  for (let i = 0; i < history.value.length; i++) {
    const entry = history.value[i]
    const prev = history.value[i + 1]
    if (!prev || entry.status !== prev.status || entry.root_cause !== prev.root_cause) {
      changes.push(entry)
    }
  }
  return changes.slice(0, 10)
})

async function fetchHealth() {
  try {
    const res = await api.get('/nas/health')
    if (res.data?.success) {
      health.value = res.data.data
      error.value = ''
    }
  } catch (e) {
    error.value = e.response?.data?.error || 'Failed to load health status'
  } finally {
    loading.value = false
  }
}

async function runCheck() {
  checking.value = true
  try {
    const res = await api.post('/nas/health/check')
    if (res.data?.success) {
      health.value = res.data.data
      error.value = ''
    }
  } catch (e) {
    error.value = e.response?.data?.error || 'Health check failed'
  } finally {
    checking.value = false
  }
}

async function fetchHistory() {
  try {
    const res = await api.get('/nas/health/history?limit=30')
    if (res.data?.success) {
      history.value = res.data.data.entries || []
    }
  } catch {
    // non-critical
  }
}

function toggleHistory() {
  showHistory.value = !showHistory.value
  if (showHistory.value && !history.value.length) {
    fetchHistory()
  }
}

function checkIcon(status) {
  return { ok: 'check_circle', error: 'cancel', warning: 'warning' }[status] || 'help_outline'
}

function checkColor(status) {
  return { ok: 'text-emerald-500', error: 'text-red-500', warning: 'text-amber-500' }[status] || 'text-gray-400'
}

function historyColor(status) {
  return { healthy: 'bg-emerald-500', degraded: 'bg-amber-500', down: 'bg-red-500' }[status] || 'bg-gray-400'
}

onMounted(() => {
  fetchHealth()
  // Poll every 15s so the dashboard reflects the daemon's live state (and
  // any automatic recovery) shortly after it happens, instead of lagging a
  // full minute behind.
  pollTimer = setInterval(fetchHealth, 15000)
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})
</script>

<template>
  <div class="rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden mb-6">
    <!-- Header -->
    <div
      class="flex items-center justify-between px-5 py-4 transition-colors"
      :class="statusBg"
    >
      <div class="flex items-center gap-3">
        <span
          class="material-symbols-rounded text-2xl"
          :style="{ color: statusColor }"
        >{{ statusIcon }}</span>
        <div>
          <div class="flex items-center gap-2">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">NAS / VPN Health</h3>
            <span
              class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold text-white"
              :style="{ backgroundColor: statusColor }"
            >{{ health?.status?.toUpperCase() || 'LOADING' }}</span>
            <span
              v-if="isStale && !loading"
              class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-500 text-white"
              title="The monitor daemon has not published fresh state recently. The status shown may be out of date."
            >
              <span class="material-symbols-rounded text-xs">schedule</span>
              STALE
            </span>
          </div>
          <p v-if="health?.root_cause" class="text-xs mt-0.5" :style="{ color: statusColor }">
            {{ health.root_cause }}
          </p>
          <p v-else-if="health?.status === 'healthy'" class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            {{ statusLabel }}
          </p>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <span class="text-xs text-gray-400">{{ timeSinceCheck }}</span>
        <button
          @click="runCheck"
          :disabled="checking"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors disabled:opacity-50"
        >
          <span class="material-symbols-rounded text-sm" :class="{ 'animate-spin': checking }">
            {{ checking ? 'progress_activity' : 'refresh' }}
          </span>
          {{ checking ? 'Checking...' : 'Check Now' }}
        </button>
        <button
          @click="toggleHistory"
          class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-medium text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
        >
          <span class="material-symbols-rounded text-sm">history</span>
          History
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="px-5 py-8 text-center text-gray-400 text-sm">
      <span class="material-symbols-rounded animate-spin text-lg mr-2">progress_activity</span>
      Loading health status...
    </div>

    <!-- Error -->
    <div v-else-if="error" class="px-5 py-4 bg-red-50 text-red-600 text-sm">
      <span class="material-symbols-rounded text-sm align-middle mr-1">error</span>
      {{ error }}
    </div>

    <!-- Checks grid -->
    <div v-else-if="health && checksList.length" class="px-5 py-4">
      <!-- Root cause detail -->
      <div
        v-if="health.root_cause_detail && health.status !== 'healthy'"
        class="mb-4 px-4 py-3 rounded-lg text-sm"
        :class="{
          'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-800': health.status === 'down',
          'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800': health.status === 'degraded',
        }"
      >
        <span class="font-semibold">Root Cause:</span> {{ health.root_cause_detail }}
      </div>

      <!-- Auto-recovery notice -->
      <div
        v-if="health.auto_recovery?.attempted"
        class="mb-4 px-4 py-3 rounded-lg text-sm border"
        :class="health.auto_recovery.success
          ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800'
          : 'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-300 border-red-200 dark:border-red-800'"
      >
        <span class="material-symbols-rounded text-sm align-middle mr-1">
          {{ health.auto_recovery.success ? 'auto_fix_high' : 'auto_fix_off' }}
        </span>
        <span class="font-semibold">Auto-Recovery:</span>
        {{ health.auto_recovery.action }}
        -- {{ health.auto_recovery.success ? 'Succeeded' : 'Failed' }}
      </div>

      <!-- Recovery paused (breaker quarantine / permanent block) -->
      <div
        v-if="recoveryPaused"
        class="mb-4 px-4 py-3 rounded-lg text-sm border bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800"
      >
        <span class="material-symbols-rounded text-sm align-middle mr-1">pause_circle</span>
        <span class="font-semibold">Auto-Recovery Paused:</span>
        <template v-if="recoveryBreaker?.permanent">
          permanently blocked after repeated failures -- operator action required (clear the recovery breaker, then check the NAS/VPN).
        </template>
        <template v-else>
          backing off after repeated failed attempts<template v-if="recoveryBreaker?.quarantined_for_sec">
            -- retrying in ~{{ Math.ceil(recoveryBreaker.quarantined_for_sec / 60) }}m</template>.
        </template>
      </div>

      <!-- Check items -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div
          v-for="c in checksList"
          :key="c.key"
          class="flex items-start gap-2.5 px-3 py-2.5 rounded-lg bg-gray-50 dark:bg-gray-800/50"
        >
          <span
            class="material-symbols-rounded text-lg mt-0.5 flex-shrink-0"
            :class="checkColor(c.status)"
          >{{ checkIcon(c.status) }}</span>
          <div class="min-w-0">
            <div class="text-xs font-semibold text-gray-700 dark:text-gray-200">{{ c.label }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ c.message }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- No data -->
    <div v-else-if="health?.status === 'unknown'" class="px-5 py-6 text-center text-sm text-gray-400">
      <span class="material-symbols-rounded text-3xl mb-2 block">monitor_heart</span>
      No health check data yet. Click "Check Now" to run the first check,
      or install the cron monitor for automatic checks every 3 minutes.
    </div>

    <!-- History panel -->
    <div v-if="showHistory" class="border-t border-gray-200 dark:border-gray-700 px-5 py-4 bg-gray-50 dark:bg-gray-800/50">
      <h4 class="text-xs font-semibold text-gray-600 dark:text-gray-300 mb-3 flex items-center gap-1.5">
        <span class="material-symbols-rounded text-sm">timeline</span>
        Recent State Changes
      </h4>
      <div v-if="!historyChanges.length" class="text-xs text-gray-400">No history available</div>
      <div v-else class="space-y-2">
        <div
          v-for="(entry, i) in historyChanges"
          :key="i"
          class="flex items-center gap-3 text-xs"
        >
          <span
            class="w-2 h-2 rounded-full flex-shrink-0"
            :class="historyColor(entry.status)"
          />
          <span class="text-gray-400 w-32 flex-shrink-0">{{ entry.timestamp }}</span>
          <span class="font-medium" :class="{
            'text-emerald-600': entry.status === 'healthy',
            'text-amber-600': entry.status === 'degraded',
            'text-red-600': entry.status === 'down',
          }">{{ entry.status?.toUpperCase() }}</span>
          <span v-if="entry.root_cause" class="text-gray-500 truncate">{{ entry.root_cause }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
