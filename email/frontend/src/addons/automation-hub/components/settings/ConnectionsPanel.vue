<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="$emit('close')">
    <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-xl mx-4 overflow-hidden">
      <!-- Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-surface-200 dark:border-surface-700">
        <div class="flex items-center gap-3">
          <span class="material-symbols-rounded text-xl text-primary-500">cable</span>
          <h2 class="text-lg font-semibold text-surface-800 dark:text-surface-100">Connections</h2>
        </div>
        <button
          @click="$emit('close')"
          class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 transition-colors"
        >
          <span class="material-symbols-rounded text-xl">close</span>
        </button>
      </div>

      <!-- Body -->
      <div class="p-6 space-y-3 max-h-[70vh] overflow-y-auto">
        <p class="text-xs text-surface-500 dark:text-surface-400 mb-3">
          External service connections are managed in Settings. Connect services to unlock additional automation nodes.
        </p>

        <!-- Service list -->
        <div
          v-for="svc in services"
          :key="svc.id"
          class="flex items-center gap-4 p-4 rounded-xl border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50"
        >
          <div :class="['w-10 h-10 rounded-xl flex items-center justify-center', svc.bgClass]">
            <span :class="['material-symbols-rounded text-xl', svc.iconClass]">{{ svc.icon }}</span>
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-semibold text-surface-800 dark:text-surface-100">{{ svc.label }}</div>
            <div class="text-xs text-surface-500">{{ svc.description }}</div>
          </div>
          <span
            v-if="isConnected(svc.id)"
            class="px-3 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-500"
          >Connected</span>
          <span
            v-else
            class="px-3 py-1 rounded-full text-xs font-medium bg-surface-200 dark:bg-surface-600 text-surface-500 dark:text-surface-400"
          >Not set</span>
        </div>
      </div>

      <!-- Footer -->
      <div class="px-6 py-3 border-t border-surface-200 dark:border-surface-700 flex items-center justify-between">
        <button
          @click="goToSettings"
          class="flex items-center gap-2 px-5 py-2 rounded-full bg-primary-500 text-white text-sm font-medium hover:bg-primary-600 transition-colors"
        >
          <span class="material-symbols-rounded text-lg">settings</span>
          Manage in Settings
        </button>
        <button
          @click="$emit('close')"
          class="px-5 py-2 rounded-full text-sm font-medium text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
        >
          Close
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAutomationData } from '../../composables/useAutomationData'

const emit = defineEmits(['close'])
const router = useRouter()
const { connections, fetchConnections, resetConnectionsCache } = useAutomationData()

const services = [
  { id: 'google', label: 'Google', description: 'Gmail, Contacts, Calendar sync', icon: 'mail', bgClass: 'bg-red-500/10', iconClass: 'text-red-500' },
  { id: 'mailchimp', label: 'Mailchimp', description: 'Audiences, subscribers, campaigns', icon: 'mail', bgClass: 'bg-yellow-500/10', iconClass: 'text-yellow-600 dark:text-yellow-400' },
  { id: 'openweathermap', label: 'OpenWeatherMap', description: 'Weather data for automations', icon: 'cloud', bgClass: 'bg-sky-500/10', iconClass: 'text-sky-500' },
]

onMounted(() => {
  resetConnectionsCache()
  fetchConnections()
})

function isConnected(id) {
  return !!connections.value[id]?.connected
}

function goToSettings() {
  emit('close')
  router.push('/settings?tab=integrations')
}
</script>
