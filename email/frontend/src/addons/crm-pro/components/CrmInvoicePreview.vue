<script setup>
/**
 * CrmInvoicePreview - Modal for viewing an invoice, recording payments, generating PDF
 */
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const router = useRouter()

const props = defineProps({
  invoice: { type: Object, required: true },
})
const emit = defineEmits(['close', 'edit', 'refresh'])
const toast = useToastStore()

const fullInvoice = ref(null)
const loading = ref(true)
const showPaymentForm = ref(false)
const recordingPayment = ref(false)

const paymentForm = ref({
  amount: 0,
  payment_date: new Date().toISOString().split('T')[0],
  payment_method: 'bank_transfer',
  reference: '',
  notes: '',
})

const balance = computed(() => {
  if (!fullInvoice.value) return 0
  return (parseFloat(fullInvoice.value.total) || 0) - (parseFloat(fullInvoice.value.paid_amount) || 0)
})

onMounted(async () => {
  try {
    const res = await api.get(`/crm/invoices/${props.invoice.id}`)
    if (res.data?.success) {
      fullInvoice.value = res.data.data
      paymentForm.value.amount = balance.value
    }
  } catch (e) {
    toast.error('Failed to load invoice')
  } finally {
    loading.value = false
  }
})

async function recordPayment() {
  if (paymentForm.value.amount <= 0) {
    toast.error('Amount must be positive')
    return
  }
  recordingPayment.value = true
  try {
    const res = await api.post(`/crm/invoices/${props.invoice.id}/payment`, paymentForm.value)
    if (res.data?.success) {
      fullInvoice.value = res.data.data
      toast.success('Payment recorded')
      showPaymentForm.value = false
      paymentForm.value = { amount: 0, payment_date: new Date().toISOString().split('T')[0], payment_method: 'bank_transfer', reference: '', notes: '' }
      paymentForm.value.amount = balance.value
      emit('refresh')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to record payment')
  } finally {
    recordingPayment.value = false
  }
}

async function generatePdf() {
  try {
    const res = await api.get(`/crm/invoices/${props.invoice.id}/pdf`)
    if (res.data?.success && res.data.data?.html) {
      const win = window.open('', '_blank')
      win.document.write(res.data.data.html)
      win.document.close()
      setTimeout(() => win.print(), 500)
    }
  } catch (e) {
    toast.error('Failed to generate PDF')
  }
}

function formatMoney(amount, currency) {
  return new Intl.NumberFormat('hu-HU', { style: 'currency', currency: currency || 'HUF' }).format(amount || 0)
}

function formatDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

const statusColors = {
  draft: 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300',
  sent: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300',
  viewed: 'bg-cyan-100 dark:bg-cyan-500/20 text-cyan-700 dark:text-cyan-300',
  partial: 'bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300',
  paid: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300',
  overdue: 'bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-300',
  cancelled: 'bg-surface-100 dark:bg-surface-700 text-surface-400',
  refunded: 'bg-purple-100 dark:bg-purple-500/20 text-purple-700 dark:text-purple-300',
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-start justify-center pt-8 pb-8 bg-black/50 overflow-auto" @click.self="emit('close')">
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-2xl mx-4">
        <!-- Header -->
        <div class="flex items-center justify-between p-6 border-b border-surface-200 dark:border-surface-700">
          <div>
            <h2 class="text-lg font-bold text-surface-900 dark:text-white">{{ fullInvoice?.invoice_number || props.invoice.invoice_number }}</h2>
            <span v-if="fullInvoice" :class="['inline-block mt-1 px-2.5 py-0.5 rounded-full text-xs font-medium', statusColors[fullInvoice.status] || '']">
              {{ fullInvoice.status }}
            </span>
          </div>
          <div class="flex items-center gap-2">
            <button @click="generatePdf" class="p-2 rounded-lg text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700" title="Print / PDF">
              <span class="material-symbols-rounded">print</span>
            </button>
            <button @click="emit('edit', fullInvoice || invoice)" class="p-2 rounded-lg text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700" title="Edit">
              <span class="material-symbols-rounded">edit</span>
            </button>
            <button @click="emit('close')" class="p-2 rounded-lg text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="p-12 text-center">
          <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
        </div>

        <div v-else-if="fullInvoice" class="p-6 space-y-6 max-h-[calc(100vh-200px)] overflow-auto">
          <!-- Meta -->
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div><span class="text-surface-400">Issue Date</span><br><span class="font-medium text-surface-800 dark:text-white">{{ formatDate(fullInvoice.issue_date) }}</span></div>
            <div><span class="text-surface-400">Due Date</span><br><span class="font-medium text-surface-800 dark:text-white">{{ formatDate(fullInvoice.due_date) }}</span></div>
          </div>

          <!-- Board/Card origin link -->
          <div
            v-if="fullInvoice.board_card_id"
            class="flex items-center gap-2 px-3 py-2 rounded-lg bg-primary-50 dark:bg-primary-900/10 border border-primary-200 dark:border-primary-800 text-sm cursor-pointer hover:bg-primary-100 dark:hover:bg-primary-900/20 transition-colors"
            @click="router.push(`/boards?card=${fullInvoice.board_card_id}`)"
          >
            <span class="material-symbols-rounded text-base text-primary-500">dashboard</span>
            <span class="text-primary-700 dark:text-primary-400">Linked to Board Card #{{ fullInvoice.board_card_id }}</span>
          </div>

          <!-- Items -->
          <div>
            <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-200 mb-2">Items</h3>
            <table class="w-full text-sm">
              <thead>
                <tr class="text-xs text-surface-400 uppercase border-b border-surface-200 dark:border-surface-700">
                  <th class="pb-2 text-left">Description</th>
                  <th class="pb-2 text-center">Qty</th>
                  <th class="pb-2 text-right">Price</th>
                  <th class="pb-2 text-right">Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in fullInvoice.items" :key="item.id" class="border-b border-surface-100 dark:border-surface-700/50">
                  <td class="py-2 text-surface-700 dark:text-surface-200">{{ item.description }}</td>
                  <td class="py-2 text-center text-surface-500">{{ item.quantity }} {{ item.unit }}</td>
                  <td class="py-2 text-right text-surface-500">{{ formatMoney(item.unit_price, fullInvoice.currency) }}</td>
                  <td class="py-2 text-right font-medium text-surface-700 dark:text-surface-200">{{ formatMoney(item.total, fullInvoice.currency) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Totals -->
          <div class="bg-surface-50 dark:bg-surface-700/50 rounded-xl p-4 space-y-1">
            <div class="flex justify-between text-sm"><span class="text-surface-500">Subtotal</span><span>{{ formatMoney(fullInvoice.subtotal, fullInvoice.currency) }}</span></div>
            <div v-if="fullInvoice.tax_rate > 0" class="flex justify-between text-sm"><span class="text-surface-500">Tax ({{ fullInvoice.tax_rate }}%)</span><span>{{ formatMoney(fullInvoice.tax_amount, fullInvoice.currency) }}</span></div>
            <div v-if="fullInvoice.discount_amount > 0" class="flex justify-between text-sm"><span class="text-surface-500">Discount</span><span class="text-red-500">-{{ formatMoney(fullInvoice.discount_amount, fullInvoice.currency) }}</span></div>
            <div class="flex justify-between text-lg font-bold border-t border-surface-200 dark:border-surface-600 pt-2">
              <span>Total</span><span class="text-primary-600 dark:text-primary-400">{{ formatMoney(fullInvoice.total, fullInvoice.currency) }}</span>
            </div>
            <div v-if="fullInvoice.paid_amount > 0" class="flex justify-between text-sm text-green-600 dark:text-green-400">
              <span>Paid</span><span>{{ formatMoney(fullInvoice.paid_amount, fullInvoice.currency) }}</span>
            </div>
            <div v-if="balance > 0" class="flex justify-between text-sm font-semibold text-amber-600 dark:text-amber-400">
              <span>Balance Due</span><span>{{ formatMoney(balance, fullInvoice.currency) }}</span>
            </div>
          </div>

          <!-- Notes -->
          <div v-if="fullInvoice.notes" class="text-sm">
            <h3 class="font-semibold text-surface-700 dark:text-surface-200 mb-1">Notes</h3>
            <p class="text-surface-500 whitespace-pre-wrap">{{ fullInvoice.notes }}</p>
          </div>

          <!-- Payments -->
          <div v-if="fullInvoice.payments?.length > 0">
            <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-200 mb-2">Payment History</h3>
            <div class="space-y-2">
              <div v-for="p in fullInvoice.payments" :key="p.id"
                   class="flex items-center gap-3 p-3 bg-green-50 dark:bg-green-500/10 rounded-lg text-sm">
                <span class="material-symbols-rounded text-green-500">payments</span>
                <div class="flex-1">
                  <span class="font-medium text-green-700 dark:text-green-300">{{ formatMoney(p.amount, fullInvoice.currency) }}</span>
                  <span class="text-surface-400 ml-2">{{ p.payment_method || '' }}</span>
                  <span v-if="p.reference" class="text-surface-400 ml-2">Ref: {{ p.reference }}</span>
                </div>
                <span class="text-surface-400 text-xs">{{ formatDate(p.payment_date) }}</span>
              </div>
            </div>
          </div>

          <!-- Record Payment -->
          <div v-if="balance > 0">
            <button v-if="!showPaymentForm" @click="showPaymentForm = true; paymentForm.amount = balance"
                    class="w-full py-3 rounded-xl border-2 border-dashed border-green-300 dark:border-green-500/30 text-green-600 dark:text-green-400 text-sm font-medium hover:bg-green-50 dark:hover:bg-green-500/10 flex items-center justify-center gap-2">
              <span class="material-symbols-rounded">add</span> Record Payment
            </button>
            <div v-else class="p-4 bg-surface-50 dark:bg-surface-700/50 rounded-xl space-y-3">
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1">Amount</label>
                  <input v-model.number="paymentForm.amount" type="number" min="0" :max="balance"
                         class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1">Date</label>
                  <input v-model="paymentForm.payment_date" type="date"
                         class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1">Method</label>
                  <select v-model="paymentForm.payment_method"
                          class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="paypal">PayPal</option>
                    <option value="other">Other</option>
                  </select>
                </div>
                <div>
                  <label class="block text-xs font-medium text-surface-600 dark:text-surface-300 mb-1">Reference</label>
                  <input v-model="paymentForm.reference" placeholder="Transaction ID"
                         class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
                </div>
              </div>
              <div class="flex justify-end gap-2">
                <button @click="showPaymentForm = false" class="px-4 py-2 text-sm text-surface-500">Cancel</button>
                <button @click="recordPayment" :disabled="recordingPayment"
                        class="px-5 py-2 rounded-xl bg-green-600 hover:bg-green-700 text-white text-sm font-medium disabled:opacity-50">
                  {{ recordingPayment ? 'Recording...' : 'Record Payment' }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

