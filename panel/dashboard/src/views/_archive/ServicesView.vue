<script setup>
import { ref, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import StatusBadge from '@/components/StatusBadge.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'

const toast = useToastStore()

const loading = ref(true)
const services = ref([])
const actionLoading = ref({})
const confirmModal = ref({
  show: false,
  service: null,
  action: ''
})

const fetchServices = async () => {
  try {
    const response = await api.get('/services')
    if (response.data.success) {
      services.value = response.data.data.services || []
    }
  } catch (e) {
    toast.error('Failed to load services')
  } finally {
    loading.value = false
  }
}

const performAction = async (service, action) => {
  actionLoading.value[service.name] = action
  
  try {
    const response = await api.post(`/services/${service.name}/${action}`)
    if (response.data.success) {
      toast.success(response.data.message || `Service ${action}ed`)
      await fetchServices()
    } else {
      toast.error(response.data.error || `Failed to ${action} service`)
    }
  } catch (e) {
    toast.error(e.response?.data?.error || `Failed to ${action} service`)
  } finally {
    actionLoading.value[service.name] = null
    confirmModal.value.show = false
  }
}

const showConfirm = (service, action) => {
  confirmModal.value = {
    show: true,
    service,
    action,
    message: `Are you sure you want to ${action} ${service.name}?`
  }
}

const handleConfirm = () => {
  if (confirmModal.value.service && confirmModal.value.action) {
    performAction(confirmModal.value.service, confirmModal.value.action)
  }
}

onMounted(fetchServices)
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Services</h1>
        <p class="text-surface-500 text-sm mt-1">Manage system services</p>
      </div>
      <button @click="fetchServices" class="btn-secondary" :disabled="loading">
        <span class="material-symbols-rounded" :class="loading && 'animate-spin'">refresh</span>
        Refresh
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner"></span>
    </div>

    <!-- Services table -->
    <div v-else-if="services.length" class="card overflow-hidden">
      <table class="table">
        <thead>
          <tr class="bg-surface-50 dark:bg-surface-800/50">
            <th>Service</th>
            <th>Status</th>
            <th>Enabled</th>
            <th>Uptime</th>
            <th>Memory</th>
            <th>PID</th>
            <th class="text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="service in services" :key="service.name">
            <td>
              <div class="flex items-center gap-3">
                <div :class="[
                  'w-10 h-10 rounded-xl flex items-center justify-center',
                  service.active 
                    ? 'bg-green-100 dark:bg-green-500/20' 
                    : 'bg-red-100 dark:bg-red-500/20'
                ]">
                  <span :class="[
                    'material-symbols-rounded',
                    service.active ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'
                  ]">dns</span>
                </div>
                <span class="font-semibold">{{ service.name }}</span>
              </div>
            </td>
            <td>
              <StatusBadge :status="service.active ? 'running' : 'stopped'" />
            </td>
            <td>
              <span :class="service.enabled ? 'text-green-500' : 'text-surface-400'">
                {{ service.enabled ? 'Yes' : 'No' }}
              </span>
            </td>
            <td class="text-surface-500">
              {{ service.uptime || '—' }}
            </td>
            <td class="text-surface-500">
              {{ service.memory || '—' }}
            </td>
            <td class="font-mono text-surface-500 text-sm">
              {{ service.pid || '—' }}
            </td>
            <td>
              <div class="flex justify-end gap-1">
                <!-- Reload -->
                <button
                  v-if="service.active"
                  @click="performAction(service, 'reload')"
                  class="btn-ghost btn-sm"
                  :disabled="actionLoading[service.name]"
                  title="Reload"
                >
                  <span v-if="actionLoading[service.name] === 'reload'" class="spinner"></span>
                  <span v-else class="material-symbols-rounded">sync</span>
                </button>
                
                <!-- Restart -->
                <button
                  v-if="service.active"
                  @click="showConfirm(service, 'restart')"
                  class="btn-ghost btn-sm"
                  :disabled="actionLoading[service.name]"
                  title="Restart"
                >
                  <span v-if="actionLoading[service.name] === 'restart'" class="spinner"></span>
                  <span v-else class="material-symbols-rounded">restart_alt</span>
                </button>

                <!-- Stop -->
                <button
                  v-if="service.active"
                  @click="showConfirm(service, 'stop')"
                  class="btn-ghost btn-sm text-red-500"
                  :disabled="actionLoading[service.name]"
                  title="Stop"
                >
                  <span v-if="actionLoading[service.name] === 'stop'" class="spinner"></span>
                  <span v-else class="material-symbols-rounded">stop_circle</span>
                </button>

                <!-- Start -->
                <button
                  v-if="!service.active"
                  @click="performAction(service, 'start')"
                  class="btn-primary btn-sm"
                  :disabled="actionLoading[service.name]"
                  title="Start"
                >
                  <span v-if="actionLoading[service.name] === 'start'" class="spinner"></span>
                  <span v-else class="material-symbols-rounded">play_circle</span>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Empty state -->
    <div v-else class="card p-12 text-center">
      <span class="material-symbols-rounded text-5xl text-surface-300 mb-4 block">dns</span>
      <h3 class="text-lg font-medium mb-2">No Services</h3>
      <p class="text-surface-500">No services are configured for management.</p>
    </div>

    <!-- Confirm modal -->
    <ConfirmModal
      :show="confirmModal.show"
      :title="`${confirmModal.action} Service`"
      :message="confirmModal.message"
      :confirm-text="confirmModal.action"
      :danger="confirmModal.action === 'stop'"
      :loading="!!actionLoading[confirmModal.service?.name]"
      :require-confirmation="confirmModal.action === 'stop' ? 'STOP' : ''"
      @confirm="handleConfirm"
      @cancel="confirmModal.show = false"
    />
  </div>
</template>
