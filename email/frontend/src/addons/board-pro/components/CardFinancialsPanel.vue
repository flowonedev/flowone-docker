<template>
  <div class="boardpro-card-financials">
    <div class="flex items-center justify-end mb-2">
      <button
        v-if="!editing"
        class="text-xs text-primary-500 dark:text-primary-400 hover:underline"
        @click="editing = true"
      >
        Edit
      </button>
      <div v-else class="flex gap-1">
        <button
          class="px-2 py-0.5 text-xs rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors"
          @click="save"
        >
          Save
        </button>
        <button
          class="px-2 py-0.5 text-xs rounded-full bg-surface-200 dark:bg-surface-700 hover:bg-surface-300 dark:hover:bg-surface-600 text-surface-700 dark:text-surface-300 transition-colors"
          @click="cancel"
        >
          Cancel
        </button>
      </div>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-4">
      <span class="material-symbols-rounded animate-spin text-surface-400">progress_activity</span>
    </div>

    <div v-else class="space-y-2.5">
      <!-- Revenue -->
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="text-xs text-surface-500 dark:text-surface-400">Revenue</label>
          <div v-if="!editing" class="text-sm font-medium text-surface-800 dark:text-surface-200">
            {{ formatMoney(financials.estimated_revenue, financials.currency) }}
          </div>
          <input
            v-else
            v-model.number="form.estimated_revenue"
            type="number"
            step="0.01"
            class="w-full text-sm border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-800 text-surface-900 dark:text-surface-100 rounded-lg px-2 py-1"
            placeholder="0.00"
          />
        </div>
        <div>
          <label class="text-xs text-surface-500 dark:text-surface-400">Cost</label>
          <div v-if="!editing" class="text-sm font-medium text-surface-800 dark:text-surface-200">
            {{ formatMoney(financials.estimated_cost, financials.currency) }}
          </div>
          <input
            v-else
            v-model.number="form.estimated_cost"
            type="number"
            step="0.01"
            class="w-full text-sm border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-800 text-surface-900 dark:text-surface-100 rounded-lg px-2 py-1"
            placeholder="0.00"
          />
        </div>
      </div>

      <!-- Margin -->
      <div class="flex items-center gap-2">
        <span class="text-xs text-surface-500 dark:text-surface-400">Margin:</span>
        <span
          class="text-xs font-semibold px-1.5 py-0.5 rounded-full"
          :class="marginClass"
        >
          {{ marginValue }}
        </span>
      </div>

      <!-- Currency + Time Budget -->
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label class="text-xs text-surface-500 dark:text-surface-400">Currency</label>
          <div v-if="!editing" class="text-sm text-surface-800 dark:text-surface-200">{{ financials.currency || 'HUF' }}</div>
          <select
            v-else
            v-model="form.currency"
            class="w-full text-sm border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-800 text-surface-900 dark:text-surface-100 rounded-lg px-2 py-1"
          >
            <option value="HUF">HUF</option>
            <option value="EUR">EUR</option>
            <option value="USD">USD</option>
            <option value="GBP">GBP</option>
          </select>
        </div>
        <div>
          <label class="text-xs text-surface-500 dark:text-surface-400">Time Budget (h)</label>
          <div v-if="!editing" class="text-sm text-surface-800 dark:text-surface-200">
            {{ financials.time_budget_hours ?? '-' }}
          </div>
          <input
            v-else
            v-model.number="form.time_budget_hours"
            type="number"
            step="0.5"
            class="w-full text-sm border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-800 text-surface-900 dark:text-surface-100 rounded-lg px-2 py-1"
            placeholder="0"
          />
        </div>
      </div>

      <!-- Invoice Status + Linkage (requires CRM Pro) -->
      <div v-if="crmProEnabled">
        <label class="text-xs text-surface-500 dark:text-surface-400">Invoice</label>
        <div class="flex items-center gap-1.5 mt-0.5">
          <span
            class="text-xs font-medium px-2 py-0.5 rounded-full"
            :class="invoiceStatusClass(financials.invoice_status)"
          >
            {{ (financials.invoice_status || 'none').toUpperCase() }}
          </span>
          <button
            v-if="financials.linked_invoice_id"
            class="text-xs text-primary-500 hover:text-primary-600 flex items-center gap-0.5"
            @click="goToInvoice"
            title="Open linked CRM invoice"
          >
            <span class="material-symbols-rounded text-sm">receipt_long</span>
            #{{ financials.linked_invoice_id }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useBoardProStore } from '../stores/boardPro'
import { useAddons } from '@/composables/useAddons'

const router = useRouter()
const { crmProEnabled } = useAddons()

const props = defineProps({
  cardId: { type: Number, required: true },
})

const store = useBoardProStore()

const editing = ref(false)
const loading = computed(() => store.cardFinancialsLoading)

const financials = computed(() => store.cardFinancials[props.cardId] || {
  estimated_revenue: null,
  estimated_cost: null,
  currency: 'HUF',
  time_budget_hours: null,
  invoice_status: 'none',
  margin: 0,
})

const form = reactive({
  estimated_revenue: null,
  estimated_cost: null,
  currency: 'HUF',
  time_budget_hours: null,
})

const marginValue = computed(() => {
  const rev = financials.value.estimated_revenue || 0
  const cost = financials.value.estimated_cost || 0
  if (rev === 0) return '0%'
  return ((rev - cost) / rev * 100).toFixed(1) + '%'
})

const marginClass = computed(() => {
  const rev = financials.value.estimated_revenue || 0
  const cost = financials.value.estimated_cost || 0
  const pct = rev > 0 ? ((rev - cost) / rev * 100) : 0
  if (pct >= 30) return 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
  if (pct >= 10) return 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-400'
  return 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400'
})

function formatMoney(value, currency) {
  if (value === null || value === undefined) return '-'
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: currency || 'HUF',
    minimumFractionDigits: 0,
  }).format(value)
}

function invoiceStatusClass(status) {
  switch (status) {
    case 'paid': return 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
    case 'sent': return 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400'
    case 'draft': return 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300'
    case 'overdue': return 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400'
    default: return 'bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400'
  }
}

async function save() {
  try {
    await store.updateCardFinancials(props.cardId, form)
    editing.value = false
  } catch (e) { /* handled in store */ }
}

function goToInvoice() {
  if (financials.value.linked_invoice_id) {
    router.push(`/crm/invoices?open=${financials.value.linked_invoice_id}`)
  }
}

function cancel() {
  Object.assign(form, {
    estimated_revenue: financials.value.estimated_revenue,
    estimated_cost: financials.value.estimated_cost,
    currency: financials.value.currency || 'HUF',
    time_budget_hours: financials.value.time_budget_hours,
  })
  editing.value = false
}

onMounted(async () => {
  await store.fetchCardFinancials(props.cardId)
  Object.assign(form, {
    estimated_revenue: financials.value.estimated_revenue,
    estimated_cost: financials.value.estimated_cost,
    currency: financials.value.currency || 'HUF',
    time_budget_hours: financials.value.time_budget_hours,
  })
})
</script>

