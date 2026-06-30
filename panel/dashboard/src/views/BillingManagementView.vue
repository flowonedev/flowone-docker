<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

// Active tab
const activeTab = ref('clients')

const tabs = [
  { id: 'clients', label: 'Clients', icon: 'group' },
  { id: 'billing', label: 'Billing', icon: 'payments' },
  { id: 'payments', label: 'Payments', icon: 'receipt_long' },
]

// Set active tab from route query
onMounted(() => {
  if (route.query.tab && tabs.find(t => t.id === route.query.tab)) {
    activeTab.value = route.query.tab
  }
})

// Update URL when tab changes
watch(activeTab, (newTab) => {
  router.replace({ query: { tab: newTab } })
})

// ============================================
// Shared State
// ============================================
const clients = ref([])
const sites = ref([])

const fetchClients = async () => {
  try {
    const response = await api.get('/clients')
    if (response.data.success) {
      clients.value = response.data.data.clients || []
    }
  } catch (e) {
    toast.error('Failed to load clients')
  }
}

const fetchSites = async () => {
  try {
    const response = await api.get('/sites')
    if (response.data.success) {
      sites.value = (response.data.data.vhosts || []).filter(
        site => !site.domain.startsWith('mail.')
      )
    }
  } catch (e) {
    // Silently fail
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

// ============================================
// Clients Tab State & Logic
// ============================================
const clientsLoading = ref(true)
const clientCreateModal = ref(false)
const clientDeleteModal = ref({ show: false, client: null })
const clientSubmitting = ref(false)

const clientSearchQuery = ref('')
const clientFilterStatus = ref('all')

const newClient = ref({
  name: '',
  email: '',
  phone: '',
  company: '',
  address: '',
  notes: '',
  status: 'active',
  domains: []
})

const filteredClients = computed(() => {
  let result = [...clients.value]
  
  if (clientSearchQuery.value) {
    const query = clientSearchQuery.value.toLowerCase()
    result = result.filter(client => 
      client.name.toLowerCase().includes(query) ||
      client.email.toLowerCase().includes(query) ||
      (client.company && client.company.toLowerCase().includes(query))
    )
  }
  
  if (clientFilterStatus.value !== 'all') {
    result = result.filter(client => client.status === clientFilterStatus.value)
  }
  
  return result
})

const clientStats = computed(() => ({
  total: clients.value.length,
  active: clients.value.filter(c => c.status === 'active').length,
  withSubscriptions: clients.value.filter(c => c.active_subscriptions > 0).length
}))

const loadClientsTab = async () => {
  clientsLoading.value = true
  await fetchClients()
  await fetchSites()
  clientsLoading.value = false
}

const createClient = async () => {
  clientSubmitting.value = true
  try {
    const response = await api.post('/clients', newClient.value)
    if (response.data.success) {
      toast.success('Client created successfully')
      clientCreateModal.value = false
      resetNewClient()
      await fetchClients()
    } else {
      toast.error(response.data.error || 'Failed to create client')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create client')
  } finally {
    clientSubmitting.value = false
  }
}

const deleteClient = async () => {
  if (!clientDeleteModal.value.client) return
  
  clientSubmitting.value = true
  try {
    const response = await api.delete(`/clients/${clientDeleteModal.value.client.id}`)
    if (response.data.success) {
      toast.success('Client deleted successfully')
      clientDeleteModal.value = { show: false, client: null }
      await fetchClients()
    } else {
      toast.error(response.data.error || 'Failed to delete client')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete client')
  } finally {
    clientSubmitting.value = false
  }
}

const toggleDomain = (domain) => {
  const index = newClient.value.domains.indexOf(domain)
  if (index > -1) {
    newClient.value.domains.splice(index, 1)
  } else {
    newClient.value.domains.push(domain)
  }
}

const resetNewClient = () => {
  newClient.value = {
    name: '',
    email: '',
    phone: '',
    company: '',
    address: '',
    notes: '',
    status: 'active',
    domains: []
  }
}

// ============================================
// Billing Tab State & Logic
// ============================================
const billingLoading = ref(true)
const billingStats = ref(null)
const upcoming = ref([])
const overdue = ref([])

const fetchBillingStats = async () => {
  try {
    const response = await api.get('/billing/stats')
    if (response.data.success) {
      billingStats.value = response.data.data
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
  }
}

const loadBillingTab = async () => {
  billingLoading.value = true
  await Promise.all([fetchBillingStats(), fetchUpcoming(), fetchOverdue()])
  billingLoading.value = false
}

const daysUntilDue = (dueDate) => {
  const diff = new Date(dueDate) - new Date()
  return Math.ceil(diff / (1000 * 60 * 60 * 24))
}

// ============================================
// Payments Tab State & Logic
// ============================================
const paymentsLoading = ref(true)
const payments = ref([])
const paymentDeleteModal = ref({ show: false, payment: null })
const paymentModal = ref(false)
const paymentSubmitting = ref(false)

const filterClient = ref('')
const filterFromDate = ref('')
const filterToDate = ref('')

const newPayment = ref({
  client_id: '',
  amount: '',
  payment_date: new Date().toISOString().split('T')[0],
  payment_method: '',
  transaction_ref: '',
  notes: ''
})

const totalAmount = computed(() => {
  return payments.value.reduce((sum, p) => sum + parseFloat(p.amount), 0)
})

const fetchPayments = async () => {
  try {
    let url = '/billing/payments?limit=200'
    if (filterClient.value) url += `&client_id=${filterClient.value}`
    if (filterFromDate.value) url += `&from_date=${filterFromDate.value}`
    if (filterToDate.value) url += `&to_date=${filterToDate.value}`
    
    const response = await api.get(url)
    if (response.data.success) {
      payments.value = response.data.data.payments || []
    }
  } catch (e) {
    toast.error('Failed to load payments')
  }
}

const loadPaymentsTab = async () => {
  paymentsLoading.value = true
  await fetchPayments()
  if (clients.value.length === 0) await fetchClients()
  paymentsLoading.value = false
}

const recordPayment = async () => {
  paymentSubmitting.value = true
  try {
    const payload = {
      ...newPayment.value,
      currency: 'HUF'
    }
    const response = await api.post('/billing/payments', payload)
    if (response.data.success) {
      toast.success('Payment recorded successfully')
      paymentModal.value = false
      resetNewPayment()
      await fetchPayments()
    } else {
      toast.error(response.data.error || 'Failed to record payment')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to record payment')
  } finally {
    paymentSubmitting.value = false
  }
}

const deletePayment = async () => {
  if (!paymentDeleteModal.value.payment) return
  
  paymentSubmitting.value = true
  try {
    const response = await api.delete(`/billing/payments/${paymentDeleteModal.value.payment.id}`)
    if (response.data.success) {
      toast.success('Payment deleted')
      paymentDeleteModal.value = { show: false, payment: null }
      await fetchPayments()
    } else {
      toast.error(response.data.error || 'Failed to delete payment')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete payment')
  } finally {
    paymentSubmitting.value = false
  }
}

const applyFilters = () => {
  paymentsLoading.value = true
  fetchPayments().finally(() => paymentsLoading.value = false)
}

const clearFilters = () => {
  filterClient.value = ''
  filterFromDate.value = ''
  filterToDate.value = ''
  paymentsLoading.value = true
  fetchPayments().finally(() => paymentsLoading.value = false)
}

const resetNewPayment = () => {
  newPayment.value = {
    client_id: '',
    amount: '',
    payment_date: new Date().toISOString().split('T')[0],
    payment_method: '',
    transaction_ref: '',
    notes: ''
  }
}

// ============================================
// Load data based on active tab
// ============================================
const loadTabData = (tab) => {
  switch (tab) {
    case 'clients':
      if (clients.value.length === 0) loadClientsTab()
      else clientsLoading.value = false
      break
    case 'billing':
      if (!billingStats.value) loadBillingTab()
      else billingLoading.value = false
      break
    case 'payments':
      if (payments.value.length === 0) loadPaymentsTab()
      else paymentsLoading.value = false
      break
  }
}

watch(activeTab, (newTab) => {
  loadTabData(newTab)
}, { immediate: true })

onMounted(() => {
  loadTabData(activeTab.value)
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="page-header">
      <div>
        <h1 class="page-title">Billing Management</h1>
        <p class="text-surface-500 text-sm mt-1 hidden sm:block">Manage clients, subscriptions, and payments</p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="border-b border-surface-200 dark:border-surface-700 mb-6 overflow-x-auto scrollbar-none">
      <nav class="flex gap-1 -mb-px min-w-max">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="[
            'flex items-center gap-1.5 sm:gap-2 px-2.5 sm:px-4 py-3 text-sm font-medium border-b-2 transition-colors whitespace-nowrap',
            activeTab === tab.id
              ? 'border-primary-500 text-primary-600 dark:text-primary-400'
              : 'border-transparent text-surface-500 hover:text-surface-700 dark:hover:text-surface-300'
          ]"
          :title="tab.label"
        >
          <span class="material-symbols-rounded text-lg">{{ tab.icon }}</span>
          <span class="hidden sm:inline">{{ tab.label }}</span>
          <span 
            v-if="tab.id === 'clients' && clients.length" 
            class="text-xs px-1.5 py-0.5 rounded-full bg-surface-100 dark:bg-surface-700"
          >
            {{ clients.length }}
          </span>
        </button>
      </nav>
    </div>

    <!-- Clients Tab -->
    <div v-if="activeTab === 'clients'" class="space-y-4 sm:space-y-6">
      <!-- Actions -->
      <div class="flex justify-end">
        <button @click="clientCreateModal = true" class="btn-primary">
          <span class="material-symbols-rounded">person_add</span>
          <span class="hidden sm:inline">New Client</span>
        </button>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-3 gap-3 sm:gap-4">
        <div class="stat-card">
          <p class="text-surface-500 text-xs sm:text-sm">Total Clients</p>
          <p class="stat-value">{{ clientStats.total }}</p>
        </div>
        <div class="stat-card">
          <p class="text-surface-500 text-xs sm:text-sm">Active</p>
          <p class="stat-value text-green-600">{{ clientStats.active }}</p>
        </div>
        <div class="stat-card">
          <p class="text-surface-500 text-xs sm:text-sm">Subscriptions</p>
          <p class="stat-value text-primary-600">{{ clientStats.withSubscriptions }}</p>
        </div>
      </div>

      <!-- Filters -->
      <div class="card p-3 sm:p-4">
        <div class="flex flex-col sm:flex-row gap-3 sm:gap-4 sm:items-center">
          <div class="relative flex-1 min-w-0">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
            <input
              v-model="clientSearchQuery"
              type="text"
              class="input pl-10"
              placeholder="Search clients..."
            />
          </div>
          
          <select v-model="clientFilterStatus" class="input sm:w-auto">
            <option value="all">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="clientsLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <!-- Clients table -->
      <div v-else class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="table min-w-[600px]">
            <thead>
              <tr class="bg-surface-50 dark:bg-surface-800/50">
                <th>Client</th>
                <th class="hidden sm:table-cell">Contact</th>
                <th class="hidden md:table-cell">Domains</th>
                <th class="hidden md:table-cell">Subscriptions</th>
                <th>Status</th>
                <th class="text-right">Actions</th>
              </tr>
            </thead>
          <tbody>
            <tr v-for="client in filteredClients" :key="client.id">
              <td>
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-xl bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center">
                    <span class="material-symbols-rounded text-primary-600 dark:text-primary-400">person</span>
                  </div>
                  <div>
                    <button 
                      @click="router.push(`/clients/${client.id}`)"
                      class="font-medium hover:text-primary-500 transition-colors text-left"
                    >
                      {{ client.name }}
                    </button>
                    <p v-if="client.company" class="text-sm text-surface-500">{{ client.company }}</p>
                  </div>
                </div>
              </td>
              <td>
                <div class="text-sm">
                  <p>{{ client.email }}</p>
                  <p v-if="client.phone" class="text-surface-500">{{ client.phone }}</p>
                </div>
              </td>
              <td>
                <span class="text-sm">{{ client.domain_count || 0 }} domain(s)</span>
              </td>
              <td>
                <span 
                  :class="[
                    'badge',
                    client.active_subscriptions > 0 ? 'badge-success' : 'badge-warning'
                  ]"
                >
                  {{ client.active_subscriptions || 0 }} active
                </span>
              </td>
              <td>
                <StatusBadge :status="client.status" />
              </td>
              <td class="text-right">
                <div class="flex justify-end gap-1">
                  <button 
                    @click="router.push(`/clients/${client.id}`)"
                    class="btn-ghost btn-sm text-primary-500"
                    title="View details"
                  >
                    <span class="material-symbols-rounded">visibility</span>
                  </button>
                  <button 
                    @click="clientDeleteModal = { show: true, client }"
                    class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                    title="Delete client"
                  >
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!filteredClients.length">
              <td colspan="6" class="py-12 text-center text-surface-400">
                <span class="material-symbols-rounded text-4xl mb-2 block">group</span>
                No clients found
              </td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- Billing Tab -->
    <div v-if="activeTab === 'billing'" class="space-y-6">
      <!-- Loading -->
      <div v-if="billingLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <div v-else>
        <!-- Stats Grid -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
          <div class="stat-card">
            <p class="text-surface-500 text-sm">Active Clients</p>
            <p class="stat-value">{{ billingStats?.clients || 0 }}</p>
          </div>
          <div class="stat-card">
            <p class="text-surface-500 text-sm">Active Subscriptions</p>
            <p class="stat-value">{{ billingStats?.active_subscriptions || 0 }}</p>
          </div>
          <div class="stat-card">
            <p class="text-surface-500 text-sm">Revenue This Month</p>
            <p class="stat-value text-green-600">{{ formatCurrency(billingStats?.revenue_this_month || 0) }}</p>
          </div>
          <div class="stat-card">
            <p class="text-surface-500 text-sm">Revenue This Year</p>
            <p class="stat-value text-green-600">{{ formatCurrency(billingStats?.revenue_this_year || 0) }}</p>
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
                <p class="text-2xl font-bold text-primary-600">{{ formatCurrency(billingStats?.mrr || 0) }}</p>
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
                <p class="text-2xl font-bold text-green-600">{{ formatCurrency(billingStats?.arr || 0) }}</p>
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

    <!-- Payments Tab -->
    <div v-if="activeTab === 'payments'" class="space-y-6">
      <!-- Actions -->
      <div class="flex justify-end">
        <button @click="paymentModal = true" class="btn-primary">
          <span class="material-symbols-rounded">add</span>
          Record Payment
        </button>
      </div>

      <!-- Filters -->
      <div class="card p-4">
        <div class="flex flex-wrap gap-4 items-end">
          <div class="flex-1 min-w-[200px]">
            <label class="block text-sm font-medium mb-1">Client</label>
            <select v-model="filterClient" class="input">
              <option value="">All Clients</option>
              <option v-for="client in clients" :key="client.id" :value="client.id">
                {{ client.name }}
              </option>
            </select>
          </div>
          
          <div>
            <label class="block text-sm font-medium mb-1">From Date</label>
            <input v-model="filterFromDate" type="date" class="input" />
          </div>
          
          <div>
            <label class="block text-sm font-medium mb-1">To Date</label>
            <input v-model="filterToDate" type="date" class="input" />
          </div>
          
          <div class="flex gap-2">
            <button @click="applyFilters" class="btn-primary">
              <span class="material-symbols-rounded">filter_list</span>
              Filter
            </button>
            <button @click="clearFilters" class="btn-secondary">
              Clear
            </button>
          </div>
        </div>
      </div>

      <!-- Summary -->
      <div class="card p-4 flex items-center justify-between">
        <div class="flex items-center gap-2">
          <span class="material-symbols-rounded text-primary-500">payments</span>
          <span>{{ payments.length }} payment(s)</span>
        </div>
        <div class="text-lg font-semibold text-green-600">
          Total: {{ formatCurrency(totalAmount) }}
        </div>
      </div>

      <!-- Loading -->
      <div v-if="paymentsLoading" class="flex items-center justify-center py-12">
        <span class="spinner"></span>
      </div>

      <!-- Payments table -->
      <div v-else class="card overflow-hidden">
        <table class="table">
          <thead>
            <tr class="bg-surface-50 dark:bg-surface-800/50">
              <th>Date</th>
              <th>Client</th>
              <th>Plan</th>
              <th>Amount</th>
              <th>Method</th>
              <th>Reference</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="payment in payments" :key="payment.id">
              <td>{{ formatDate(payment.payment_date) }}</td>
              <td>
                <button 
                  @click="router.push(`/clients/${payment.client_id}`)"
                  class="font-medium hover:text-primary-500 transition-colors text-left"
                >
                  {{ payment.client_name }}
                </button>
              </td>
              <td>
                <span class="text-sm text-surface-500">{{ payment.subscription_plan || '-' }}</span>
              </td>
              <td>
                <span class="font-semibold text-green-600">{{ formatCurrency(payment.amount, payment.currency) }}</span>
              </td>
              <td>
                <span class="text-sm">{{ payment.payment_method || '-' }}</span>
              </td>
              <td>
                <span class="text-sm text-surface-500 font-mono">{{ payment.transaction_ref || '-' }}</span>
              </td>
              <td class="text-right">
                <button 
                  @click="paymentDeleteModal = { show: true, payment }"
                  class="btn-ghost btn-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10"
                  title="Delete"
                >
                  <span class="material-symbols-rounded">delete</span>
                </button>
              </td>
            </tr>
            <tr v-if="!payments.length">
              <td colspan="7" class="py-12 text-center text-surface-400">
                <span class="material-symbols-rounded text-4xl mb-2 block">receipt_long</span>
                No payments found
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create Client Modal -->
    <Modal :show="clientCreateModal" title="Add New Client" @close="clientCreateModal = false">
      <form @submit.prevent="createClient" class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div class="col-span-2">
            <label class="block text-sm font-medium mb-2">Name *</label>
            <input
              v-model="newClient.name"
              type="text"
              class="input"
              placeholder="John Doe"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-2">Email *</label>
            <input
              v-model="newClient.email"
              type="email"
              class="input"
              placeholder="john@example.com"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-2">Phone</label>
            <input
              v-model="newClient.phone"
              type="text"
              class="input"
              placeholder="+36 30 123 4567"
            />
          </div>

          <div class="col-span-2">
            <label class="block text-sm font-medium mb-2">Company</label>
            <input
              v-model="newClient.company"
              type="text"
              class="input"
              placeholder="Company Ltd."
            />
          </div>

          <div class="col-span-2">
            <label class="block text-sm font-medium mb-2">Address</label>
            <textarea
              v-model="newClient.address"
              class="input"
              rows="2"
              placeholder="Full address"
            />
          </div>

          <div class="col-span-2">
            <label class="block text-sm font-medium mb-2">Notes</label>
            <textarea
              v-model="newClient.notes"
              class="input"
              rows="2"
              placeholder="Internal notes..."
            />
          </div>
        </div>

        <!-- Domain assignment -->
        <div v-if="sites.length > 0">
          <label class="block text-sm font-medium mb-2">Assign Domains</label>
          <div class="max-h-[200px] overflow-y-auto space-y-2 border border-surface-200 dark:border-surface-700 rounded-xl p-3">
            <label
              v-for="site in sites"
              :key="site.domain"
              class="flex items-center gap-2 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800 cursor-pointer"
            >
              <input
                type="checkbox"
                :checked="newClient.domains.includes(site.domain)"
                @change="toggleDomain(site.domain)"
                class="rounded border-surface-300"
              />
              <span>{{ site.domain }}</span>
            </label>
          </div>
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="clientCreateModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="clientSubmitting">
            <span v-if="clientSubmitting" class="spinner"></span>
            Add Client
          </button>
        </div>
      </form>
    </Modal>

    <!-- Delete Client Modal -->
    <ConfirmModal
      :show="clientDeleteModal.show"
      title="Delete Client"
      :message="`Are you sure you want to delete '${clientDeleteModal.client?.name}'? This will also delete all their subscriptions and payment history.`"
      confirm-text="Delete"
      :danger="true"
      :loading="clientSubmitting"
      @confirm="deleteClient"
      @cancel="clientDeleteModal = { show: false, client: null }"
    />

    <!-- Record Payment Modal -->
    <Modal :show="paymentModal" title="Record Payment" @close="paymentModal = false">
      <form @submit.prevent="recordPayment" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Client *</label>
          <select v-model="newPayment.client_id" class="input" required>
            <option value="">Select client</option>
            <option v-for="client in clients" :key="client.id" :value="client.id">
              {{ client.name }}
            </option>
          </select>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Amount (HUF) *</label>
            <input
              v-model="newPayment.amount"
              type="number"
              class="input"
              placeholder="50000"
              min="0"
              step="1"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-2">Payment Date *</label>
            <input
              v-model="newPayment.payment_date"
              type="date"
              class="input"
              required
            />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Payment Method</label>
          <select v-model="newPayment.payment_method" class="input">
            <option value="">Select method</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="card">Card</option>
            <option value="cash">Cash</option>
            <option value="paypal">PayPal</option>
            <option value="other">Other</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Transaction Reference</label>
          <input
            v-model="newPayment.transaction_ref"
            type="text"
            class="input"
            placeholder="Transaction ID or reference"
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Notes</label>
          <textarea
            v-model="newPayment.notes"
            class="input"
            rows="2"
            placeholder="Additional notes..."
          />
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="paymentModal = false" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="paymentSubmitting">
            <span v-if="paymentSubmitting" class="spinner"></span>
            Record Payment
          </button>
        </div>
      </form>
    </Modal>

    <!-- Delete Payment Modal -->
    <ConfirmModal
      :show="paymentDeleteModal.show"
      title="Delete Payment"
      message="Are you sure you want to delete this payment record? This action cannot be undone."
      confirm-text="Delete"
      :danger="true"
      :loading="paymentSubmitting"
      @confirm="deletePayment"
      @cancel="paymentDeleteModal = { show: false, payment: null }"
    />
  </div>
</template>

