<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const router = useRouter()
const toast = useToastStore()

const loading = ref(true)
const stats = ref(null)
const upcoming = ref([])
const overdue = ref([])

const fetchStats = async () => {
  try {
    const response = await api.get('/billing/stats')
    if (response.data.success) {
      stats.value = response.data.data
    }
  } catch (e) {
    toast.error('Failed to load billing stats')
  }
}

const fetchUpcoming = async () => {
  try {
    const response = await api.get('/billing/upcoming?days=30')
    if (response.data.success) {
      upcoming.value = response.data.data.subscriptions || []
    }
  } catch (e) {
    // Silently fail
  }
}

const fetchOverdue = async () => {
  try {
    const response = await api.get('/billing/overdue')
    if (response.data.success) {
      overdue.value = response.data.data.subscriptions || []
    }
  } catch (e) {
    // Silently fail
  } finally {
    loading.value = false
  }
}

const formatCurrency = (amount, currency = 'HUF') => {
  return new Intl.NumberFormat('hu-HU', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 0
  }).format(amount)
}

const formatDate = (date) => {
  if (!date) return '-'
  return new Date(date).toLocaleDateString('hu-HU')
}

const daysUntilDue = (dueDate) => {
  const diff = new Date(dueDate) - new Date()
  return Math.ceil(diff / (1000 * 60 * 60 * 24))
}

onMounted(() => {
  fetchStats()
  fetchUpcoming()
  fetchOverdue()
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Billing Overview</h1>
        <p class="text-surface-500 text-sm mt-1">Monitor subscriptions and revenue</p>
      </div>
      <div class="flex gap-2">
        <button @click="router.push('/payments')" class="btn-secondary">
          <span class="material-symbols-rounded">receipt_long</span>
          All Payments
        </button>
        <button @click="router.push('/clients')" class="btn-primary">
          <span class="material-symbols-rounded">group</span>
          Clients
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner"></span>
    </div>

    <div v-else>
      <!-- Stats Grid -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="stat-card">
          <p class="text-surface-500 text-sm">Active Clients</p>
          <p class="stat-value">{{ stats?.clients || 0 }}</p>
        </div>
        <div class="stat-card">
          <p class="text-surface-500 text-sm">Active Subscriptions</p>
          <p class="stat-value">{{ stats?.active_subscriptions || 0 }}</p>
        </div>
        <div class="stat-card">
          <p class="text-surface-500 text-sm">Revenue This Month</p>
          <p class="stat-value text-green-600">{{ formatCurrency(stats?.revenue_this_month || 0) }}</p>
        </div>
        <div class="stat-card">
          <p class="text-surface-500 text-sm">Revenue This Year</p>
          <p class="stat-value text-green-600">{{ formatCurrency(stats?.revenue_this_year || 0) }}</p>
        </div>
      </div>

      <!-- MRR/ARR -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div class="card p-6">
          <div class="flex items-center gap-3 mb-4">
            <div class="w-12 h-12 rounded-xl bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-primary-600 dark:text-primary-400 text-2xl">trending_up</span>
            </div>
            <div>
              <p class="text-sm text-surface-500">Monthly Recurring Revenue</p>
              <p class="text-2xl font-bold text-primary-600">{{ formatCurrency(stats?.mrr || 0) }}</p>
            </div>
          </div>
          <p class="text-sm text-surface-500">Based on active subscriptions (yearly divided by 12)</p>
        </div>

        <div class="card p-6">
          <div class="flex items-center gap-3 mb-4">
            <div class="w-12 h-12 rounded-xl bg-green-100 dark:bg-green-500/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-green-600 dark:text-green-400 text-2xl">savings</span>
            </div>
            <div>
              <p class="text-sm text-surface-500">Annual Recurring Revenue</p>
              <p class="text-2xl font-bold text-green-600">{{ formatCurrency(stats?.arr || 0) }}</p>
            </div>
          </div>
          <p class="text-sm text-surface-500">Projected annual revenue from subscriptions</p>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Overdue -->
        <div class="card">
          <div class="p-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
            <h3 class="font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-red-500">warning</span>
              Overdue ({{ overdue.length }})
            </h3>
            <span v-if="overdue.length" class="badge badge-error">
              {{ formatCurrency(overdue.reduce((sum, s) => sum + parseFloat(s.amount), 0)) }}
            </span>
          </div>
          
          <div v-if="overdue.length" class="divide-y divide-surface-200 dark:divide-surface-700">
            <div 
              v-for="sub in overdue" 
              :key="sub.id"
              class="p-4 hover:bg-surface-50 dark:hover:bg-surface-800/50 cursor-pointer"
              @click="router.push(`/clients/${sub.client_id}`)"
            >
              <div class="flex items-center justify-between">
                <div>
                  <p class="font-medium">{{ sub.client_name }}</p>
                  <p class="text-sm text-surface-500">{{ sub.plan_name }}</p>
                </div>
                <div class="text-right">
                  <p class="font-semibold text-red-600">{{ formatCurrency(sub.amount) }}</p>
                  <p class="text-sm text-red-500">{{ sub.days_overdue }} days overdue</p>
                </div>
              </div>
            </div>
          </div>
          <div v-else class="p-8 text-center text-surface-400">
            <span class="material-symbols-rounded text-4xl mb-2 block text-green-500">check_circle</span>
            No overdue payments
          </div>
        </div>

        <!-- Upcoming -->
        <div class="card">
          <div class="p-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
            <h3 class="font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-amber-500">schedule</span>
              Due in 30 Days ({{ upcoming.length }})
            </h3>
            <span v-if="upcoming.length" class="badge badge-warning">
              {{ formatCurrency(upcoming.reduce((sum, s) => sum + parseFloat(s.amount), 0)) }}
            </span>
          </div>
          
          <div v-if="upcoming.length" class="divide-y divide-surface-200 dark:divide-surface-700">
            <div 
              v-for="sub in upcoming" 
              :key="sub.id"
              class="p-4 hover:bg-surface-50 dark:hover:bg-surface-800/50 cursor-pointer"
              @click="router.push(`/clients/${sub.client_id}`)"
            >
              <div class="flex items-center justify-between">
                <div>
                  <p class="font-medium">{{ sub.client_name }}</p>
                  <p class="text-sm text-surface-500">{{ sub.plan_name }}</p>
                </div>
                <div class="text-right">
                  <p class="font-semibold">{{ formatCurrency(sub.amount) }}</p>
                  <p class="text-sm text-surface-500">
                    {{ formatDate(sub.next_due_date) }}
                    <span class="text-amber-500">({{ daysUntilDue(sub.next_due_date) }}d)</span>
                  </p>
                </div>
              </div>
            </div>
          </div>
          <div v-else class="p-8 text-center text-surface-400">
            <span class="material-symbols-rounded text-4xl mb-2 block">event_available</span>
            No upcoming payments
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

