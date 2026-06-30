<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
import StatusBadge from '@/components/StatusBadge.vue'

const loading = ref(true)
const data = ref({
  services: [],
  sites_count: 0,
  databases_count: 0,
  certificates_count: 0,
  system: {},
  recent_activity: []
})
const stats = ref({
  cpu: {},
  memory: {},
  disk: [],
  uptime: null
})

const fetchDashboard = async () => {
  try {
    const response = await api.get('/dashboard')
    if (response.data.success) {
      data.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch dashboard:', e)
  }
}

const fetchStats = async () => {
  try {
    const response = await api.get('/dashboard/stats')
    if (response.data.success) {
      stats.value = response.data.data
    }
  } catch (e) {
    console.error('Failed to fetch stats:', e)
  }
}

onMounted(async () => {
  await Promise.all([fetchDashboard(), fetchStats()])
  loading.value = false
})

const formatTime = (dateStr) => {
  const date = new Date(dateStr)
  return date.toLocaleString()
}
</script>

<template>
  <div class="space-y-4 sm:space-y-6">
    <!-- Stats grid -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
      <!-- Sites -->
      <div class="stat-card">
        <div class="flex items-center gap-2 sm:gap-3 mb-2 sm:mb-3">
          <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-blue-100 dark:bg-blue-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-blue-600 dark:text-blue-400 text-lg sm:text-xl">language</span>
          </div>
        </div>
        <div class="stat-value">{{ data.sites_count }}</div>
        <div class="stat-label">Active Sites</div>
      </div>

      <!-- Databases -->
      <div class="stat-card">
        <div class="flex items-center gap-2 sm:gap-3 mb-2 sm:mb-3">
          <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-purple-100 dark:bg-purple-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-purple-600 dark:text-purple-400 text-lg sm:text-xl">database</span>
          </div>
        </div>
        <div class="stat-value">{{ data.databases_count }}</div>
        <div class="stat-label">Databases</div>
      </div>

      <!-- SSL Certificates -->
      <div class="stat-card">
        <div class="flex items-center gap-2 sm:gap-3 mb-2 sm:mb-3">
          <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-green-100 dark:bg-green-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-green-600 dark:text-green-400 text-lg sm:text-xl">verified_user</span>
          </div>
        </div>
        <div class="stat-value">{{ data.certificates_count }}</div>
        <div class="stat-label">SSL Certs</div>
      </div>

      <!-- Uptime -->
      <div class="stat-card">
        <div class="flex items-center gap-2 sm:gap-3 mb-2 sm:mb-3">
          <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-xl bg-amber-100 dark:bg-amber-500/20 flex items-center justify-center">
            <span class="material-symbols-rounded text-amber-600 dark:text-amber-400 text-lg sm:text-xl">schedule</span>
          </div>
        </div>
        <div class="stat-value text-xl sm:text-2xl">{{ stats.uptime?.human || '-' }}</div>
        <div class="stat-label">Uptime</div>
      </div>
    </div>

    <!-- System stats -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4">
      <!-- CPU -->
      <div class="card p-4 sm:p-5">
        <div class="flex items-center justify-between mb-3 sm:mb-4">
          <h3 class="font-medium text-sm sm:text-base">CPU Usage</h3>
          <span class="text-xl sm:text-2xl font-semibold text-primary-500">{{ stats.cpu?.usage_percent || 0 }}%</span>
        </div>
        <div class="h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
          <div 
            class="h-full bg-primary-500 rounded-full transition-all duration-500"
            :style="{ width: `${stats.cpu?.usage_percent || 0}%` }"
          ></div>
        </div>
        <div class="flex justify-between text-xs text-surface-500 mt-2">
          <span>{{ stats.cpu?.cores || 0 }} cores</span>
          <span>Load: {{ stats.cpu?.load_1 || 0 }}</span>
        </div>
      </div>

      <!-- Memory -->
      <div class="card p-4 sm:p-5">
        <div class="flex items-center justify-between mb-3 sm:mb-4">
          <h3 class="font-medium text-sm sm:text-base">Memory</h3>
          <span class="text-xl sm:text-2xl font-semibold text-purple-500">{{ stats.memory?.percent || 0 }}%</span>
        </div>
        <div class="h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
          <div 
            class="h-full bg-purple-500 rounded-full transition-all duration-500"
            :style="{ width: `${stats.memory?.percent || 0}%` }"
          ></div>
        </div>
        <div class="flex justify-between text-xs text-surface-500 mt-2">
          <span>{{ stats.memory?.used_human || '0 B' }}</span>
          <span>{{ stats.memory?.total_human || '0 B' }}</span>
        </div>
      </div>

      <!-- Disk -->
      <div class="card p-4 sm:p-5 sm:col-span-2 lg:col-span-1">
        <div class="flex items-center justify-between mb-3 sm:mb-4">
          <h3 class="font-medium text-sm sm:text-base truncate">Disk ({{ stats.disk?.[0]?.path || '/' }})</h3>
          <span class="text-xl sm:text-2xl font-semibold text-amber-500 shrink-0 ml-2">{{ stats.disk?.[0]?.percent || 0 }}%</span>
        </div>
        <div class="h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
          <div 
            class="h-full bg-amber-500 rounded-full transition-all duration-500"
            :style="{ width: `${stats.disk?.[0]?.percent || 0}%` }"
          ></div>
        </div>
        <div class="flex justify-between text-xs text-surface-500 mt-2">
          <span>{{ stats.disk?.[0]?.used_human || '0 B' }} used</span>
          <span>{{ stats.disk?.[0]?.free_human || '0 B' }} free</span>
        </div>
      </div>
    </div>

    <!-- Services & Activity -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
      <!-- Services -->
      <div class="card">
        <div class="card-header px-4 sm:px-6 py-3 sm:py-4">
          <h3 class="font-medium">Services</h3>
        </div>
        <div class="divide-y divide-surface-100 dark:divide-surface-800">
          <div
            v-for="service in data.services"
            :key="service.name"
            class="px-4 sm:px-6 py-2.5 sm:py-3 flex items-center justify-between hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
          >
            <div class="flex items-center gap-2 sm:gap-3 min-w-0">
              <span :class="['status-dot shrink-0', service.active ? 'running' : 'stopped']"></span>
              <span class="font-medium truncate text-sm sm:text-base">{{ service.name }}</span>
            </div>
            <div class="flex items-center gap-2 sm:gap-3 text-xs sm:text-sm text-surface-500 shrink-0">
              <span v-if="service.uptime" class="hidden sm:inline">{{ service.uptime }}</span>
              <StatusBadge :status="service.active ? 'running' : 'stopped'" :show-dot="false" />
            </div>
          </div>
          <div v-if="!data.services.length" class="px-4 sm:px-6 py-8 text-center text-surface-400">
            <span class="material-symbols-rounded text-3xl mb-2 block">dns</span>
            No services configured
          </div>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="card">
        <div class="card-header px-4 sm:px-6 py-3 sm:py-4">
          <h3 class="font-medium">Recent Activity</h3>
        </div>
        <div class="divide-y divide-surface-100 dark:divide-surface-800">
          <div
            v-for="log in data.recent_activity"
            :key="log.id"
            class="px-4 sm:px-6 py-2.5 sm:py-3 hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
          >
            <div class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-2 min-w-0">
                <span :class="[
                  'material-symbols-rounded text-base sm:text-lg shrink-0',
                  log.outcome === 'success' ? 'text-green-500' : 'text-red-500'
                ]">
                  {{ log.outcome === 'success' ? 'check_circle' : 'error' }}
                </span>
                <span class="font-medium text-xs sm:text-sm truncate">{{ log.action }}</span>
              </div>
              <span class="text-xs text-surface-400 shrink-0 hidden sm:inline">{{ formatTime(log.created_at) }}</span>
            </div>
            <div class="text-xs sm:text-sm text-surface-500 mt-1 ml-6 sm:ml-7 truncate">
              {{ log.target }}
            </div>
          </div>
          <div v-if="!data.recent_activity.length" class="px-4 sm:px-6 py-8 text-center text-surface-400">
            <span class="material-symbols-rounded text-3xl mb-2 block">history</span>
            No recent activity
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

