<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import Modal from '@/components/Modal.vue'
import ConfirmModal from '@/components/ConfirmModal.vue'

const router = useRouter()
const toast = useToastStore()

const loading = ref(true)
const payments = ref([])
const clients = ref([])
const deleteModal = ref({ show: false, payment: null })
const paymentModal = ref(false)
const submitting = ref(false)

// Filters
const filterClient = ref('')
const filterFromDate = ref('')
const filterToDate = ref('')

// New payment
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
  } finally {
    loading.value = false
  }
}

const fetchClients = async () => {
  try {
    const response = await api.get('/clients')
    if (response.data.success) {
      clients.value = response.data.data.clients || []
    }
  } catch (e) {
    // Silently fail
  }
}

const recordPayment = async () => {
  submitting.value = true
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
    submitting.value = false
  }
}

const deletePayment = async () => {
  if (!deleteModal.value.payment) return
  
  submitting.value = true
  try {
    const response = await api.delete(`/billing/payments/${deleteModal.value.payment.id}`)
    if (response.data.success) {
      toast.success('Payment deleted')
      deleteModal.value = { show: false, payment: null }
      await fetchPayments()
    } else {
      toast.error(response.data.error || 'Failed to delete payment')
    }
  } catch (e) {
    toast.error(e.response?.data?.error || 'Failed to delete payment')
  } finally {
    submitting.value = false
  }
}

const applyFilters = () => {
  loading.value = true
  fetchPayments()
}

const clearFilters = () => {
  filterClient.value = ''
  filterFromDate.value = ''
  filterToDate.value = ''
  loading.value = true
  fetchPayments()
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

onMounted(() => {
  fetchPayments()
  fetchClients()
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Payments</h1>
        <p class="text-surface-500 text-sm mt-1">Payment history and records</p>
      </div>
      <button @click="paymentModal = true" class="btn-primary">
        <span class="material-symbols-rounded">add</span>
        Record Payment
      </button>
    </div>

    <!-- Filters -->
    <div class="card p-4 mb-6">
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
    <div class="card p-4 mb-6 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <span class="material-symbols-rounded text-primary-500">payments</span>
        <span>{{ payments.length }} payment(s)</span>
      </div>
      <div class="text-lg font-semibold text-green-600">
        Total: {{ formatCurrency(totalAmount) }}
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
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
                @click="deleteModal = { show: true, payment }"
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
          <button type="submit" class="btn-primary" :disabled="submitting">
            <span v-if="submitting" class="spinner"></span>
            Record Payment
          </button>
        </div>
      </form>
    </Modal>

    <!-- Delete Modal -->
    <ConfirmModal
      :show="deleteModal.show"
      title="Delete Payment"
      message="Are you sure you want to delete this payment record? This action cannot be undone."
      confirm-text="Delete"
      :danger="true"
      :loading="submitting"
      @confirm="deletePayment"
      @cancel="deleteModal = { show: false, payment: null }"
    />
  </div>
</template>

