<template>
  <div class="boardpro-card-invoice">

    <!-- Already has linked invoice -->
    <div v-if="linkedInvoice" class="p-3 rounded-xl border border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800/50 space-y-2">
      <div class="flex items-center justify-between">
        <span class="text-xs font-medium text-surface-800 dark:text-surface-200">
          {{ linkedInvoice.invoice_number || `#${linkedInvoice.id}` }}
        </span>
        <span
          class="text-xs px-2 py-0.5 rounded-full font-medium"
          :class="statusClass(linkedInvoice.status)"
        >{{ (linkedInvoice.status || 'draft').toUpperCase() }}</span>
      </div>
      <div class="flex justify-between text-xs text-surface-500">
        <span>{{ linkedInvoice.currency || 'HUF' }} {{ formatMoney(linkedInvoice.total_amount) }}</span>
        <span>Due: {{ linkedInvoice.due_date || '-' }}</span>
      </div>
      <button
        class="text-xs text-primary-500 hover:text-primary-600 flex items-center gap-0.5"
        @click="openInCrm"
      >
        <span class="material-symbols-rounded text-sm">open_in_new</span>
        View in CRM
      </button>
    </div>

    <!-- No invoice yet, show create option if card has financials -->
    <div v-else>
      <div v-if="!hasFinancials" class="text-xs text-surface-400 py-3 text-center">
        Set revenue/cost on this card first to create an invoice.
      </div>

      <div v-else class="space-y-3">
        <!-- Invoice preview -->
        <div class="p-3 rounded-xl border border-dashed border-surface-300 dark:border-surface-600 bg-surface-50/50 dark:bg-surface-800/30 space-y-2">
          <p class="text-xs font-medium text-surface-600 dark:text-surface-400">Invoice Preview</p>
          <div class="space-y-1">
            <div class="flex justify-between text-xs">
              <span class="text-surface-500">Client</span>
              <span class="text-surface-800 dark:text-surface-200 font-medium">{{ clientName || 'Not set' }}</span>
            </div>
            <div class="flex justify-between text-xs">
              <span class="text-surface-500">Item</span>
              <span class="text-surface-800 dark:text-surface-200">{{ cardTitle }}</span>
            </div>
            <div class="flex justify-between text-xs">
              <span class="text-surface-500">Amount</span>
              <span class="text-surface-800 dark:text-surface-200 font-medium">
                {{ financials.currency || 'HUF' }} {{ formatMoney(financials.estimated_revenue) }}
              </span>
            </div>
            <div v-if="checklistSummary" class="flex justify-between text-xs">
              <span class="text-surface-500">Tasks</span>
              <span :class="allTasksDone ? 'text-green-600' : 'text-amber-600'">
                {{ checklistSummary }}
              </span>
            </div>
          </div>
        </div>

        <!-- Due date presets -->
        <div>
          <label class="text-xs font-medium text-surface-600 dark:text-surface-400 block mb-1">Payment Term</label>
          <div class="flex gap-1.5">
            <button
              v-for="preset in dueDatePresets"
              :key="preset.days"
              class="px-2.5 py-1 text-xs rounded-full transition-colors"
              :class="selectedDueDays === preset.days
                ? 'bg-primary-500 text-white'
                : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600'"
              @click="selectedDueDays = preset.days"
            >
              {{ preset.label }}
            </button>
          </div>
        </div>

        <!-- Tax rate -->
        <div class="flex items-center justify-between">
          <label class="text-xs font-medium text-surface-600 dark:text-surface-400">Tax Rate (%)</label>
          <input
            v-model.number="taxRate"
            type="number"
            min="0"
            max="100"
            class="w-16 text-xs text-right px-2 py-1 border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-900 rounded-lg"
          />
        </div>

        <!-- Notes -->
        <div>
          <label class="text-xs font-medium text-surface-600 dark:text-surface-400 block mb-1">Notes (optional)</label>
          <textarea
            v-model="notes"
            rows="2"
            class="w-full text-xs px-3 py-2 border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-900 rounded-xl resize-none"
            placeholder="Additional notes for the invoice..."
          ></textarea>
        </div>

        <button
          class="w-full px-3 py-2 text-xs rounded-full bg-primary-500 text-white hover:bg-primary-600 transition-colors flex items-center justify-center gap-1 disabled:opacity-50"
          :disabled="creating || !clientId"
          @click="createInvoice"
        >
          <span class="material-symbols-rounded text-sm">receipt_long</span>
          {{ creating ? 'Creating...' : 'Create Invoice Draft' }}
        </button>

        <p v-if="!clientId" class="text-xs text-amber-600 text-center">
          Assign a client to this board first to create an invoice.
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { useBoardProStore } from '../stores/boardPro'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useToastStore } from '@/stores/toast'
import { useRouter } from 'vue-router'

const props = defineProps({
  cardId: { type: Number, required: true },
  cardTitle: { type: String, default: '' },
})

const store = useBoardProStore()
const boardsStore = useBoardsStore()
const toast = useToastStore()
const router = useRouter()

const creating = ref(false)
const linkedInvoice = ref(null)
const selectedDueDays = ref(8)
const taxRate = ref(27)
const notes = ref('')

const dueDatePresets = [
  { label: '8 days', days: 8 },
  { label: '15 days', days: 15 },
  { label: '30 days', days: 30 },
]

const financials = computed(() => store.cardFinancials[props.cardId] || {})
const hasFinancials = computed(() =>
  financials.value.estimated_revenue > 0 || financials.value.estimated_cost > 0
)

const clientId = computed(() => boardsStore.currentBoard?.client_id || null)
const clientName = computed(() => boardsStore.currentBoard?.client_name || null)
const cardTitle = computed(() => props.cardTitle || 'Card')

// Get checklist completion status
const checklistSummary = computed(() => {
  const card = boardsStore.currentBoard ? null : null // We get this from the card data
  // Try to get from the card in the lists
  const lists = boardsStore.currentLists || []
  for (const list of lists) {
    const cards = list.cards || []
    const found = cards.find(c => c.id === props.cardId)
    if (found && found.checklist_total > 0) {
      return `${found.checklist_done || 0}/${found.checklist_total} done`
    }
  }
  return null
})

const allTasksDone = computed(() => {
  const lists = boardsStore.currentLists || []
  for (const list of lists) {
    const cards = list.cards || []
    const found = cards.find(c => c.id === props.cardId)
    if (found && found.checklist_total > 0) {
      return found.checklist_done >= found.checklist_total
    }
  }
  return false
})

function formatMoney(value) {
  if (value === null || value === undefined) return '0'
  return new Intl.NumberFormat(undefined, { minimumFractionDigits: 0 }).format(value)
}

function statusClass(status) {
  switch (status) {
    case 'paid': return 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
    case 'sent': return 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400'
    case 'draft': return 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300'
    case 'overdue': return 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400'
    default: return 'bg-surface-100 dark:bg-surface-700 text-surface-500'
  }
}

function openInCrm() {
  router.push('/crm/invoices')
}

async function checkLinkedInvoice() {
  // Check if this card's financials have a linked invoice
  if (financials.value.linked_invoice_id) {
    try {
      const { data } = await api.get(`/crm/invoices/${financials.value.linked_invoice_id}`)
      if (data.success) {
        linkedInvoice.value = data.data
      }
    } catch (e) {
      // Invoice might have been deleted
    }
  }
}

async function createInvoice() {
  if (!clientId.value || !hasFinancials.value) return
  creating.value = true

  try {
    const dueDate = new Date(Date.now() + selectedDueDays.value * 86400000).toISOString().split('T')[0]
    const issueDate = new Date().toISOString().split('T')[0]

    const payload = {
      client_id: clientId.value,
      issue_date: issueDate,
      due_date: dueDate,
      currency: financials.value.currency || 'HUF',
      tax_rate: taxRate.value,
      discount_amount: 0,
      notes: notes.value || `Invoice for: ${cardTitle.value}`,
      items: [
        {
          description: cardTitle.value,
          quantity: 1,
          unit: 'pc',
          unit_price: financials.value.estimated_revenue || 0,
        }
      ],
    }

    const { data } = await api.post('/crm/invoices', payload)
    if (data.success) {
      const invoiceId = data.data?.id
      toast.success('Invoice draft created')

      // Link the invoice to the card financials
      if (invoiceId) {
        await store.updateCardFinancials(props.cardId, {
          ...financials.value,
          linked_invoice_id: invoiceId,
          invoice_status: 'draft',
        })
        linkedInvoice.value = data.data
      }
    } else {
      toast.error(data.message || 'Failed to create invoice')
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to create invoice')
  } finally {
    creating.value = false
  }
}

onMounted(async () => {
  await store.fetchCardFinancials(props.cardId)
  await checkLinkedInvoice()
})
</script>

