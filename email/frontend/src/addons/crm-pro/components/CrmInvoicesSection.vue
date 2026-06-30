<script setup>
/**
 * CrmInvoicesSection - Compact invoice list in ClientSnapshot
 * Shows recent invoices for a client with quick actions.
 */
import { ref, watch, computed } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  clientId: { type: Number, required: true },
})

const toast = useToastStore()
const invoices = ref([])
const summary = ref({})
const loading = ref(false)
const expanded = ref(false)

watch(() => props.clientId, () => fetchInvoices(), { immediate: true })

async function fetchInvoices() {
  loading.value = true
  try {
    const res = await api.get('/crm/invoices', { params: { client_id: props.clientId } })
    if (res.data?.success) {
      invoices.value = res.data.data?.invoices || []
      summary.value = res.data.data?.summary || {}
    }
  } catch (e) {
    invoices.value = []
  } finally {
    loading.value = false
  }
}

const displayInvoices = computed(() => expanded.value ? invoices.value : invoices.value.slice(0, 3))

function formatMoney(amount, currency = 'HUF') {
  return new Intl.NumberFormat('hu-HU', { style: 'currency', currency }).format(amount || 0)
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

const statusColors = {
  draft: 'text-surface-400', sent: 'text-blue-500', viewed: 'text-cyan-500',
  partial: 'text-amber-500', paid: 'text-green-500', overdue: 'text-red-500',
  cancelled: 'text-surface-300', refunded: 'text-purple-500',
}

const statusIcons = {
  draft: 'edit_note', sent: 'send', viewed: 'visibility', partial: 'payments',
  paid: 'check_circle', overdue: 'warning', cancelled: 'cancel', refunded: 'undo',
}
</script>

<template>
  <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg text-primary-500">receipt_long</span>
        Invoices
        <span v-if="invoices.length" class="text-xs font-normal text-surface-400">({{ invoices.length }})</span>
      </h3>
      <router-link to="/crm/invoices" class="text-xs text-primary-600 hover:text-primary-700 font-medium">
        View All
      </router-link>
    </div>

    <!-- Mini Summary -->
    <div v-if="summary.total_revenue > 0" class="grid grid-cols-3 gap-2 mb-3">
      <div class="text-center p-2 bg-green-50 dark:bg-green-500/10 rounded-lg">
        <p class="text-xs text-green-600 dark:text-green-400">Revenue</p>
        <p class="text-sm font-bold text-green-700 dark:text-green-300">{{ formatMoney(summary.total_revenue) }}</p>
      </div>
      <div class="text-center p-2 bg-amber-50 dark:bg-amber-500/10 rounded-lg">
        <p class="text-xs text-amber-600 dark:text-amber-400">Outstanding</p>
        <p class="text-sm font-bold text-amber-700 dark:text-amber-300">{{ formatMoney(summary.outstanding) }}</p>
      </div>
      <div class="text-center p-2 bg-blue-50 dark:bg-blue-500/10 rounded-lg">
        <p class="text-xs text-blue-600 dark:text-blue-400">Net</p>
        <p class="text-sm font-bold text-blue-700 dark:text-blue-300">{{ formatMoney(summary.net_revenue) }}</p>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-4">
      <div class="animate-spin w-5 h-5 border-2 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <!-- Invoice List -->
    <div v-else-if="displayInvoices.length" class="space-y-1.5">
      <div v-for="inv in displayInvoices" :key="inv.id"
           class="flex items-center gap-2 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors text-xs">
        <span :class="['material-symbols-rounded text-sm', statusColors[inv.status]]">{{ statusIcons[inv.status] || 'receipt' }}</span>
        <span class="font-medium text-surface-700 dark:text-surface-200 flex-1 truncate">{{ inv.invoice_number }}</span>
        <span class="text-surface-400">{{ formatDate(inv.issue_date) }}</span>
        <span class="font-semibold text-surface-700 dark:text-surface-200">{{ formatMoney(inv.total, inv.currency) }}</span>
      </div>
      <button v-if="invoices.length > 3" @click="expanded = !expanded"
              class="w-full text-center text-xs text-primary-500 hover:text-primary-600 py-1">
        {{ expanded ? 'Show less' : `Show all ${invoices.length} invoices` }}
      </button>
    </div>
    <p v-else class="text-xs text-surface-400 text-center py-2">No invoices for this client</p>
  </div>
</template>

