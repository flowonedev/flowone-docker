<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

const loading = ref(true)
const client = ref(null)
const sites = ref([])
const editing = ref(false)
const editForm = ref({})
const submitting = ref(false)

// Subscription modal
const subscriptionModal = ref({ show: false, subscription: null })
const newSubscription = ref({
  plan_name: '',
  amount: '',
  currency: 'HUF',
  billing_cycle: 'yearly',
  start_date: new Date().toISOString().split('T')[0],
  notes: ''
})

// Edit subscription modal
const editSubscriptionModal = ref({ show: false, subscription: null })
const editSubscription = ref({
  plan_name: '',
  amount: '',
  billing_cycle: 'yearly',
  next_due_date: '',
  status: 'active',
  notes: ''
})

// Payment modal
const paymentModal = ref({ show: false, subscription: null })
const newPayment = ref({
  amount: '',
  payment_date: new Date().toISOString().split('T')[0],
  payment_method: '',
  transaction_ref: '',
  notes: ''
})

// Delete modals
const deleteSubscriptionModal = ref({ show: false, subscription: null })
const deletePaymentModal = ref({ show: false, payment: null })

const clientId = computed(() => route.params.id)

const fetchClient = async () => {
  try {
    const response = await api.get(`/clients/${clientId.value}`)
    if (response.data.success) {
      client.value = response.data.data.client
      editForm.value = { ...client.value }
    } else {
      toast.error('Client not found')
      router.push('/clients')
    }
  } catch (e) {
    toast.error('Failed to load client')
    router.push('/clients')
  } finally {
    loading.value = false
  }
}

const fetchSites = async () => {
  try {
    const response = await api.get('/sites')
    if (response.data.success) {
      // Filter out mail.* domains
      sites.value = (response.data.data.vhosts || []).filter(
        site => !site.domain.startsWith('mail.')
      )
    }
  } catch (e) {
    // Silently fail
  }
}

const saveClient = async () => {
  submitting.value = true
  try {
    const response = await api.put(`/clients/${clientId.value}`, editForm.value)
    if (response.data.success) {
      toast.success('Client updated successfully')
      editing.value = false
      await fetchClient()
    } else {
      toast.error(response.data.error || 'Failed to update client')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update client')
  } finally {
    submitting.value = false
  }
}

const createSubscription = async () => {
  submitting.value = true
  try {
    const payload = {
      ...newSubscription.value,
      client_id: clientId.value
    }
    const response = await api.post('/billing/subscriptions', payload)
    if (response.data.success) {
      toast.success('Subscription created successfully')
      subscriptionModal.value = { show: false, subscription: null }
      resetNewSubscription()
      await fetchClient()
    } else {
      toast.error(response.data.error || 'Failed to create subscription')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to create subscription')
  } finally {
    submitting.value = false
  }
}

const recordPayment = async () => {
  submitting.value = true
  try {
    const payload = {
      ...newPayment.value,
      client_id: clientId.value,
      subscription_id: paymentModal.value.subscription?.id || null,
      currency: 'HUF'
    }
    const response = await api.post('/billing/payments', payload)
    if (response.data.success) {
      toast.success('Payment recorded successfully')
      paymentModal.value = { show: false, subscription: null }
      resetNewPayment()
      await fetchClient()
    } else {
      toast.error(response.data.error || 'Failed to record payment')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to record payment')
  } finally {
    submitting.value = false
  }
}

const deleteSubscription = async () => {
  if (!deleteSubscriptionModal.value.subscription) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/billing/subscriptions/${deleteSubscriptionModal.value.subscription.id}`)
    if (response.data.success) {
      toast.success('Subscription deleted')
      deleteSubscriptionModal.value = { show: false, subscription: null }
      await fetchClient()
    } else {
      toast.error(response.data.error || 'Failed to delete subscription')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete subscription')
  } finally {
    submitting.value = false
  }
}

const openEditSubscriptionModal = (sub) => {
  editSubscription.value = {
    plan_name: sub.plan_name,
    amount: sub.amount,
    billing_cycle: sub.billing_cycle,
    next_due_date: sub.next_due_date?.split('T')[0] || sub.next_due_date,
    status: sub.status,
    notes: sub.notes || ''
  }
  editSubscriptionModal.value = { show: true, subscription: sub }
}

const updateSubscription = async () => {
  if (!editSubscriptionModal.value.subscription) return
  
  submitting.value = true
  try {
    const response = await api.put(`/billing/subscriptions/${editSubscriptionModal.value.subscription.id}`, editSubscription.value)
    if (response.data.success) {
      toast.success('Subscription updated successfully')
      editSubscriptionModal.value = { show: false, subscription: null }
      await fetchClient()
    } else {
      toast.error(response.data.error || 'Failed to update subscription')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update subscription')
  } finally {
    submitting.value = false
  }
}

const toggleSubscriptionStatus = async (sub) => {
  const newStatus = sub.status === 'active' ? 'cancelled' : 'active'
  submitting.value = true
  try {
    const response = await api.put(`/billing/subscriptions/${sub.id}`, { status: newStatus })
    if (response.data.success) {
      toast.success(`Subscription ${newStatus === 'active' ? 'activated' : 'deactivated'}`)
      await fetchClient()
    } else {
      toast.error(response.data.error || 'Failed to update subscription')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to update subscription')
  } finally {
    submitting.value = false
  }
}

const deletePayment = async () => {
  if (!deletePaymentModal.value.payment) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/billing/payments/${deletePaymentModal.value.payment.id}`)
    if (response.data.success) {
      toast.success('Payment deleted')
      deletePaymentModal.value = { show: false, payment: null }
      await fetchClient()
    } else {
      toast.error(response.data.error || 'Failed to delete payment')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete payment')
  } finally {
    submitting.value = false
  }
}

const openPaymentModal = (subscription = null) => {
  newPayment.value.amount = subscription?.amount || ''
  paymentModal.value = { show: true, subscription }
}

const toggleDomain = (domain) => {
  if (!editForm.value.domains) editForm.value.domains = []
  const index = editForm.value.domains.indexOf(domain)
  if (index > -1) {
    editForm.value.domains.splice(index, 1)
  } else {
    editForm.value.domains.push(domain)
  }
}

const resetNewSubscription = () => {
  newSubscription.value = {
    plan_name: '',
    amount: '',
    currency: 'HUF',
    billing_cycle: 'yearly',
    start_date: new Date().toISOString().split('T')[0],
    notes: ''
  }
}

const resetNewPayment = () => {
  newPayment.value = {
    amount: '',
    payment_date: new Date().toISOString().split('T')[0],
    payment_method: '',
    transaction_ref: '',
    notes: ''
  }
}

const formatDate = (date) => {
  if (!date) return '-'
  return new Date(date).toLocaleDateString('hu-HU')
}

const formatCurrency = (amount, currency = 'HUF') => {
  return new Intl.NumberFormat('hu-HU', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 0
  }).format(amount)
}

const isOverdue = (dueDate) => {
  return new Date(dueDate) < new Date()
}

const daysUntilDue = (dueDate) => {
  const diff = new Date(dueDate) - new Date()
  return Math.ceil(diff / (1000 * 60 * 60 * 24))
}

onMounted(() => {
  fetchClient()
  fetchSites()
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="page-header">
      <div class="flex items-center gap-3 sm:gap-4 min-w-0">
        <button @click="router.push('/clients')" class="btn-ghost shrink-0">
          <span class="material-symbols-rounded">arrow_back</span>
        </button>
        <div class="min-w-0">
          <h1 class="page-title truncate">{{ client?.name || 'Client Details' }}</h1>
          <p class="text-surface-500 text-sm mt-1 truncate">{{ client?.email }}</p>
        </div>
      </div>
      <div class="flex gap-2 shrink-0">
        <button 
          v-if="!editing" 
          @click="editing = true; editForm = { ...client }" 
          class="btn-secondary"
        >
          <span class="material-symbols-rounded">edit</span>
          <span class="hidden sm:inline">Edit</span>
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <span class="spinner"></span>
    </div>

    <div v-else-if="client" class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-6">
      <!-- Client Info -->
      <div class="md:col-span-1 space-y-4 sm:space-y-6">
        <!-- Details Card -->
        <div class="card p-6">
          <h3 class="font-semibold mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">person</span>
            Client Information
          </h3>
          
          <form v-if="editing" @submit.prevent="saveClient" class="space-y-4">
            <div>
              <label class="block text-sm font-medium mb-1">Name</label>
              <input v-model="editForm.name" type="text" class="input" required />
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Email</label>
              <input v-model="editForm.email" type="email" class="input" required />
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Phone</label>
              <input v-model="editForm.phone" type="text" class="input" />
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Company</label>
              <input v-model="editForm.company" type="text" class="input" />
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Address</label>
              <textarea v-model="editForm.address" class="input" rows="2" />
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Status</label>
              <select v-model="editForm.status" class="input">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium mb-1">Notes</label>
              <textarea v-model="editForm.notes" class="input" rows="3" />
            </div>
            
            <!-- Domains -->
            <div v-if="sites.length > 0">
              <label class="block text-sm font-medium mb-1">Assigned Domains</label>
              <div class="max-h-[200px] overflow-y-auto space-y-1 border border-surface-200 dark:border-surface-700 rounded-lg p-2">
                <div
                  v-for="site in sites"
                  :key="site.domain"
                  class="flex items-center justify-between p-2 rounded hover:bg-surface-50 dark:hover:bg-surface-800"
                >
                  <span class="text-sm truncate mr-2">{{ site.domain }}</span>
                  <button
                    type="button"
                    @click="toggleDomain(site.domain)"
                    :class="[
                      'relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none',
                      editForm.domains?.includes(site.domain) ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                    ]"
                  >
                    <span
                      :class="[
                        'pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                        editForm.domains?.includes(site.domain) ? 'translate-x-4' : 'translate-x-0'
                      ]"
                    />
                  </button>
                </div>
              </div>
            </div>
            
            <div class="flex gap-2 pt-2">
              <button type="button" @click="editing = false" class="btn-secondary flex-1">
                Cancel
              </button>
              <button type="submit" class="btn-primary flex-1" :disabled="submitting">
                <span v-if="submitting" class="spinner"></span>
                Save
              </button>
            </div>
          </form>
          
          <div v-else class="space-y-3 text-sm">
            <div class="flex justify-between">
              <span class="text-surface-500">Status</span>
              <StatusBadge :status="client.status" />
            </div>
            <div v-if="client.company" class="flex justify-between">
              <span class="text-surface-500">Company</span>
              <span>{{ client.company }}</span>
            </div>
            <div v-if="client.phone" class="flex justify-between">
              <span class="text-surface-500">Phone</span>
              <span>{{ client.phone }}</span>
            </div>
            <div v-if="client.address">
              <span class="text-surface-500">Address</span>
              <p class="mt-1">{{ client.address }}</p>
            </div>
            <div v-if="client.notes">
              <span class="text-surface-500">Notes</span>
              <p class="mt-1 text-surface-600 dark:text-surface-400">{{ client.notes }}</p>
            </div>
            <div class="pt-2 border-t border-surface-200 dark:border-surface-700">
              <span class="text-surface-500">Created</span>
              <p>{{ formatDate(client.created_at) }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Subscriptions & Payments -->
      <div class="md:col-span-2 space-y-4 sm:space-y-6">
        <!-- Subscriptions -->
        <div class="card p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">subscriptions</span>
              Subscriptions
            </h3>
            <button @click="subscriptionModal = { show: true, subscription: null }" class="btn-primary btn-sm">
              <span class="material-symbols-rounded">add</span>
              Add
            </button>
          </div>
          
          <div v-if="client.subscriptions?.length" class="space-y-3">
            <div 
              v-for="sub in client.subscriptions" 
              :key="sub.id"
              class="p-4 border border-surface-200 dark:border-surface-700 rounded-xl"
              :class="isOverdue(sub.next_due_date) && sub.status === 'active' && 'border-red-300 dark:border-red-500/50 bg-red-50 dark:bg-red-500/10'"
            >
              <div class="flex items-start justify-between">
                <div>
                  <p class="font-medium">{{ sub.plan_name }}</p>
                  <p class="text-lg font-semibold text-primary-600">
                    {{ formatCurrency(sub.amount, sub.currency) }}
                    <span class="text-sm font-normal text-surface-500">/{{ sub.billing_cycle === 'yearly' ? 'year' : 'month' }}</span>
                    <span v-if="client.domains?.length > 1" class="text-sm font-normal text-surface-500">
                      x {{ client.domains.length }} domains = {{ formatCurrency(sub.amount * client.domains.length, sub.currency) }}
                    </span>
                  </p>
                </div>
                <div class="flex items-center gap-2">
                  <span 
                    :class="[
                      'badge',
                      sub.status === 'active' ? 'badge-success' : 'badge-warning'
                    ]"
                  >
                    {{ sub.status }}
                  </span>
                </div>
              </div>
              
              <div class="mt-3 pt-3 border-t border-surface-200 dark:border-surface-700 flex items-center justify-between text-sm">
                <div>
                  <span class="text-surface-500">Next due:</span>
                  <span 
                    class="ml-1 font-medium"
                    :class="isOverdue(sub.next_due_date) && sub.status === 'active' ? 'text-red-600' : ''"
                  >
                    {{ formatDate(sub.next_due_date) }}
                    <span v-if="isOverdue(sub.next_due_date) && sub.status === 'active'" class="text-red-500">
                      ({{ Math.abs(daysUntilDue(sub.next_due_date)) }} days overdue)
                    </span>
                    <span v-else-if="daysUntilDue(sub.next_due_date) <= 30 && sub.status === 'active'" class="text-amber-500">
                      ({{ daysUntilDue(sub.next_due_date) }} days)
                    </span>
                  </span>
                </div>
                <div class="flex gap-1">
                  <button 
                    @click="openPaymentModal(sub)"
                    class="btn-ghost btn-sm text-green-600"
                    title="Record payment"
                  >
                    <span class="material-symbols-rounded">payments</span>
                  </button>
                  <button 
                    @click="openEditSubscriptionModal(sub)"
                    class="btn-ghost btn-sm text-primary-500"
                    title="Edit subscription"
                  >
                    <span class="material-symbols-rounded">edit</span>
                  </button>
                  <button 
                    @click="toggleSubscriptionStatus(sub)"
                    class="btn-ghost btn-sm"
                    :class="sub.status === 'active' ? 'text-amber-500' : 'text-green-500'"
                    :title="sub.status === 'active' ? 'Deactivate' : 'Activate'"
                  >
                    <span class="material-symbols-rounded">{{ sub.status === 'active' ? 'pause_circle' : 'play_circle' }}</span>
                  </button>
                  <button 
                    @click="deleteSubscriptionModal = { show: true, subscription: sub }"
                    class="btn-ghost btn-sm text-red-500"
                    title="Delete"
                  >
                    <span class="material-symbols-rounded">delete</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
          <p v-else class="text-surface-400 text-sm">No subscriptions yet</p>
        </div>

        <!-- Domains Card -->
        <div class="card p-6">
          <h3 class="font-semibold mb-4 flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">language</span>
            Domains ({{ client.domains?.length || 0 }})
          </h3>
          
          <div v-if="client.domains?.length" class="flex flex-wrap gap-2">
            <div 
              v-for="domain in client.domains" 
              :key="domain"
              class="flex items-center gap-2 px-3 py-1.5 bg-surface-100 dark:bg-surface-800 rounded-full"
            >
              <span class="material-symbols-rounded text-surface-400 text-sm">link</span>
              <span class="text-sm">{{ domain }}</span>
            </div>
          </div>
          <p v-else class="text-surface-400 text-sm">No domains assigned</p>
        </div>

        <!-- Recent Payments -->
        <div class="card p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold flex items-center gap-2">
              <span class="material-symbols-rounded text-primary-500">receipt_long</span>
              Recent Payments
            </h3>
            <button @click="openPaymentModal()" class="btn-secondary btn-sm">
              <span class="material-symbols-rounded">add</span>
              Record Payment
            </button>
          </div>
          
          <div v-if="client.recent_payments?.length" class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Amount</th>
                  <th>Method</th>
                  <th>Reference</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="payment in client.recent_payments" :key="payment.id">
                  <td>{{ formatDate(payment.payment_date) }}</td>
                  <td class="font-medium text-green-600">{{ formatCurrency(payment.amount, payment.currency) }}</td>
                  <td>{{ payment.payment_method || '-' }}</td>
                  <td class="text-surface-500 text-sm">{{ payment.transaction_ref || '-' }}</td>
                  <td class="text-right">
                    <button 
                      @click="deletePaymentModal = { show: true, payment }"
                      class="btn-ghost btn-sm text-red-500"
                    >
                      <span class="material-symbols-rounded">delete</span>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="text-surface-400 text-sm">No payments recorded</p>
        </div>
      </div>
    </div>

    <!-- Subscription Modal -->
    <Modal 
      :show="subscriptionModal.show" 
      title="Add Subscription" 
      @close="subscriptionModal = { show: false, subscription: null }"
    >
      <form @submit.prevent="createSubscription" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Plan Name *</label>
          <input
            v-model="newSubscription.plan_name"
            type="text"
            class="input"
            placeholder="e.g., Basic Hosting, Premium Plan"
            required
          />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Amount (HUF) *</label>
            <input
              v-model="newSubscription.amount"
              type="number"
              class="input"
              placeholder="50000"
              min="0"
              step="1"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-2">Billing Cycle</label>
            <select v-model="newSubscription.billing_cycle" class="input">
              <option value="yearly">Yearly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Start Date *</label>
          <input
            v-model="newSubscription.start_date"
            type="date"
            class="input"
            required
          />
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Notes</label>
          <textarea
            v-model="newSubscription.notes"
            class="input"
            rows="2"
            placeholder="Additional notes..."
          />
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="subscriptionModal = { show: false, subscription: null }" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Add Subscription
          </button>
        </div>
      </form>
    </Modal>

    <!-- Payment Modal -->
    <Modal 
      :show="paymentModal.show" 
      title="Record Payment" 
      @close="paymentModal = { show: false, subscription: null }"
    >
      <form @submit.prevent="recordPayment" class="space-y-4">
        <div v-if="paymentModal.subscription" class="p-3 bg-surface-100 dark:bg-surface-800 rounded-xl">
          <p class="text-sm text-surface-500">For subscription:</p>
          <p class="font-medium">{{ paymentModal.subscription.plan_name }}</p>
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
          <button type="button" @click="paymentModal = { show: false, subscription: null }" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Record Payment
          </button>
        </div>
      </form>
    </Modal>

    <!-- Edit Subscription Modal -->
    <Modal 
      :show="editSubscriptionModal.show" 
      title="Edit Subscription" 
      @close="editSubscriptionModal = { show: false, subscription: null }"
    >
      <form @submit.prevent="updateSubscription" class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-2">Plan Name *</label>
          <input
            v-model="editSubscription.plan_name"
            type="text"
            class="input"
            placeholder="e.g., Basic Hosting, Premium Plan"
            required
          />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Amount (HUF) *</label>
            <input
              v-model="editSubscription.amount"
              type="number"
              class="input"
              placeholder="50000"
              min="0"
              step="1"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-2">Billing Cycle</label>
            <select v-model="editSubscription.billing_cycle" class="input">
              <option value="yearly">Yearly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium mb-2">Next Due Date *</label>
            <input
              v-model="editSubscription.next_due_date"
              type="date"
              class="input"
              required
            />
          </div>

          <div>
            <label class="block text-sm font-medium mb-2">Status</label>
            <select v-model="editSubscription.status" class="input">
              <option value="active">Active</option>
              <option value="cancelled">Cancelled</option>
              <option value="expired">Expired</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium mb-2">Notes</label>
          <textarea
            v-model="editSubscription.notes"
            class="input"
            rows="2"
            placeholder="Additional notes..."
          />
        </div>

        <div class="flex justify-end gap-3 pt-4">
          <button type="button" @click="editSubscriptionModal = { show: false, subscription: null }" class="btn-secondary">
            Cancel
          </button>
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Save Changes
          </button>
        </div>
      </form>
    </Modal>

    <!-- Delete Subscription Modal -->
    <ConfirmModal
      :show="deleteSubscriptionModal.show"
      title="Delete Subscription"
      :message="`Are you sure you want to delete the '${deleteSubscriptionModal.subscription?.plan_name}' subscription?`"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      @confirm="deleteSubscription"
      @cancel="deleteSubscriptionModal = { show: false, subscription: null }"
    />

    <!-- Delete Payment Modal -->
    <ConfirmModal
      :show="deletePaymentModal.show"
      title="Delete Payment"
      message="Are you sure you want to delete this payment record?"
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      @confirm="deletePayment"
      @cancel="deletePaymentModal = { show: false, payment: null }"
    />
  </div>
</template>

