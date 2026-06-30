<script setup>
/**
 * CrmInvoiceEditor - Modal for creating/editing invoices
 * Clean grouped layout with due date presets and toggle switches.
 */
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  invoice: { type: Object, default: null },
  defaultClientId: { type: Number, default: null },
})
const emit = defineEmits(['close', 'saved'])
const toast = useToastStore()

const saving = ref(false)
const clients = ref([])
const loadingClients = ref(false)

// Due date presets
const dueDatePresets = [
  { label: '8 days', days: 8 },
  { label: '15 days', days: 15 },
  { label: '30 days', days: 30 },
  { label: '60 days', days: 60 },
]
const selectedPreset = ref(8)
const customDueDate = ref(false)

function calcDueDate(days) {
  return new Date(Date.now() + days * 86400000).toISOString().split('T')[0]
}

const form = ref({
  client_id: '',
  issue_date: new Date().toISOString().split('T')[0],
  due_date: calcDueDate(8),
  currency: 'HUF',
  tax_rate: 27,
  discount_amount: 0,
  notes: '',
  internal_notes: '',
  is_recurring: false,
  recurrence_interval: 'monthly',
  recurrence_end_date: '',
  items: [{ description: '', quantity: 1, unit: '', unit_price: 0 }],
})

const isEditing = computed(() => !!props.invoice)

function applyPreset(days) {
  selectedPreset.value = days
  customDueDate.value = false
  form.value.due_date = calcDueDate(days)
}

function enableCustomDate() {
  customDueDate.value = true
  selectedPreset.value = null
}

// Detect if loaded due date matches a preset
function detectPreset(dueDate, issueDate) {
  if (!dueDate || !issueDate) return
  const diff = Math.round((new Date(dueDate) - new Date(issueDate)) / 86400000)
  const match = dueDatePresets.find(p => p.days === diff)
  if (match) {
    selectedPreset.value = match.days
    customDueDate.value = false
  } else {
    selectedPreset.value = null
    customDueDate.value = true
  }
}

onMounted(async () => {
  await fetchClients()
  if (props.invoice) {
    try {
      const res = await api.get(`/crm/invoices/${props.invoice.id}`)
      if (res.data?.success) {
        const inv = res.data.data
        form.value = {
          client_id: inv.client_id,
          issue_date: inv.issue_date,
          due_date: inv.due_date,
          currency: inv.currency || 'HUF',
          tax_rate: parseFloat(inv.tax_rate) || 0,
          discount_amount: parseFloat(inv.discount_amount) || 0,
          notes: inv.notes || '',
          internal_notes: inv.internal_notes || '',
          is_recurring: !!inv.is_recurring,
          recurrence_interval: inv.recurrence_interval || 'monthly',
          recurrence_end_date: inv.recurrence_end_date || '',
          items: inv.items?.length ? inv.items.map(it => ({
            description: it.description,
            quantity: parseFloat(it.quantity),
            unit: it.unit || '',
            unit_price: parseFloat(it.unit_price),
          })) : [{ description: '', quantity: 1, unit: '', unit_price: 0 }],
        }
        detectPreset(inv.due_date, inv.issue_date)
      }
    } catch (e) {
      // Use basic data
    }
  } else if (props.defaultClientId) {
    // Pre-fill client when creating from client snapshot
    form.value.client_id = props.defaultClientId
  }
})

async function fetchClients() {
  loadingClients.value = true
  try {
    const res = await api.get('/clients')
    if (res.data?.success) {
      clients.value = res.data.data?.clients || res.data.data || []
    }
  } catch (e) { /* ignore */ }
  loadingClients.value = false
}

const subtotal = computed(() => {
  return form.value.items.reduce((sum, item) => sum + (item.quantity || 0) * (item.unit_price || 0), 0)
})

const taxAmount = computed(() => {
  return Math.round(subtotal.value * (form.value.tax_rate || 0) / 100)
})

const total = computed(() => {
  return subtotal.value + taxAmount.value - (form.value.discount_amount || 0)
})

function addItem() {
  form.value.items.push({ description: '', quantity: 1, unit: '', unit_price: 0 })
}

function removeItem(index) {
  if (form.value.items.length > 1) {
    form.value.items.splice(index, 1)
  }
}

async function save() {
  if (!form.value.client_id) {
    toast.error('Please select a client')
    return
  }
  if (!form.value.items.some(i => i.description.trim())) {
    toast.error('Add at least one line item')
    return
  }

  saving.value = true
  try {
    const payload = {
      ...form.value,
      is_recurring: form.value.is_recurring ? 1 : 0,
      recurrence_end_date: form.value.is_recurring ? form.value.recurrence_end_date || null : null,
      recurrence_interval: form.value.is_recurring ? form.value.recurrence_interval : null,
    }

    if (isEditing.value) {
      await api.put(`/crm/invoices/${props.invoice.id}`, payload)
      toast.success('Invoice updated')
    } else {
      await api.post('/crm/invoices', payload)
      toast.success('Invoice created')
    }
    emit('saved')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to save invoice')
  } finally {
    saving.value = false
  }
}

function formatMoney(v) {
  return new Intl.NumberFormat('hu-HU').format(v || 0)
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-start justify-center pt-8 pb-8 bg-black/50 overflow-auto" @click.self="emit('close')">
      <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-3xl mx-4">
        <!-- Header -->
        <div class="flex items-center justify-between p-6 border-b border-surface-200 dark:border-surface-700">
          <h2 class="text-lg font-bold text-surface-900 dark:text-white flex items-center gap-2">
            <span class="material-symbols-rounded text-primary-500">receipt_long</span>
            {{ isEditing ? 'Edit Invoice' : 'New Invoice' }}
          </h2>
          <button @click="emit('close')" class="p-2 rounded-xl hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400 transition-colors">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <div class="p-6 space-y-5 max-h-[calc(100vh-200px)] overflow-auto">

          <!-- ============ SECTION: Client ============ -->
          <div>
            <div class="flex items-center gap-2 mb-3">
              <span class="material-symbols-rounded text-sm text-surface-400">person</span>
              <span class="text-xs font-semibold uppercase tracking-wider text-surface-400">Client</span>
            </div>
            <select v-model="form.client_id"
                    class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-900 dark:text-white focus:ring-2 focus:ring-primary-500 outline-none">
              <option value="">Select client...</option>
              <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.display_name || c.domain }}</option>
            </select>
          </div>

          <!-- ============ SECTION: Dates ============ -->
          <div>
            <div class="flex items-center gap-2 mb-3">
              <span class="material-symbols-rounded text-sm text-surface-400">calendar_month</span>
              <span class="text-xs font-semibold uppercase tracking-wider text-surface-400">Dates</span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="flex flex-col">
                <label class="block text-sm font-medium text-surface-600 dark:text-surface-300 mb-1.5">Issue Date</label>
                <input v-model="form.issue_date" type="date"
                       class="mt-auto w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-900 dark:text-white focus:ring-2 focus:ring-primary-500 outline-none" />
              </div>
              <div class="flex flex-col">
                <label class="block text-sm font-medium text-surface-600 dark:text-surface-300 mb-1.5">Due Date</label>
                <!-- Preset pills -->
                <div class="flex flex-wrap gap-1.5 mb-2">
                  <button v-for="preset in dueDatePresets" :key="preset.days"
                          @click="applyPreset(preset.days)"
                          :class="[
                            'px-3 py-1 rounded-full text-xs font-medium transition-all',
                            selectedPreset === preset.days && !customDueDate
                              ? 'bg-primary-500 text-white shadow-sm'
                              : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600'
                          ]">
                    {{ preset.label }}
                  </button>
                  <button @click="enableCustomDate"
                          :class="[
                            'px-3 py-1 rounded-full text-xs font-medium transition-all',
                            customDueDate
                              ? 'bg-primary-500 text-white shadow-sm'
                              : 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-300 hover:bg-surface-200 dark:hover:bg-surface-600'
                          ]">
                    Custom
                  </button>
                </div>
                <input v-model="form.due_date" type="date" :disabled="!customDueDate"
                       :class="[
                         'w-full px-4 py-2.5 rounded-xl border text-sm focus:ring-2 focus:ring-primary-500 outline-none transition-colors',
                         customDueDate
                           ? 'border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-surface-900 dark:text-white'
                           : 'border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800 text-surface-500 cursor-not-allowed'
                       ]" />
              </div>
            </div>
          </div>

          <!-- ============ SECTION: Line Items ============ -->
          <div>
            <div class="flex items-center justify-between mb-3">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-sm text-surface-400">list_alt</span>
                <span class="text-xs font-semibold uppercase tracking-wider text-surface-400">Line Items</span>
              </div>
              <button @click="addItem" class="flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-medium text-primary-600 bg-primary-50 dark:bg-primary-500/10 hover:bg-primary-100 dark:hover:bg-primary-500/20 transition-colors">
                <span class="material-symbols-rounded text-sm">add</span> Add Item
              </button>
            </div>

            <!-- Column headers -->
            <div class="hidden md:grid grid-cols-[1fr_70px_70px_100px_80px_32px] gap-2 px-3 mb-1">
              <span class="text-[11px] font-medium text-surface-400 uppercase tracking-wider">Description</span>
              <span class="text-[11px] font-medium text-surface-400 uppercase tracking-wider text-center">Qty</span>
              <span class="text-[11px] font-medium text-surface-400 uppercase tracking-wider text-center">Unit</span>
              <span class="text-[11px] font-medium text-surface-400 uppercase tracking-wider text-right">Price</span>
              <span class="text-[11px] font-medium text-surface-400 uppercase tracking-wider text-right">Total</span>
              <span></span>
            </div>

            <div class="space-y-1.5">
              <div v-for="(item, idx) in form.items" :key="idx"
                   class="grid grid-cols-1 md:grid-cols-[1fr_70px_70px_100px_80px_32px] gap-2 items-center p-3 bg-surface-50 dark:bg-surface-700/50 rounded-xl group">
                <input v-model="item.description" placeholder="Item description..."
                       class="w-full px-3 py-2 rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-900 dark:text-white focus:ring-2 focus:ring-primary-500 outline-none" />
                <input v-model.number="item.quantity" type="number" min="0" step="0.5" placeholder="1"
                       class="w-full px-2 py-2 rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-900 dark:text-white text-center focus:ring-2 focus:ring-primary-500 outline-none" />
                <input v-model="item.unit" placeholder="db"
                       class="w-full px-2 py-2 rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-900 dark:text-white text-center focus:ring-2 focus:ring-primary-500 outline-none" />
                <input v-model.number="item.unit_price" type="number" min="0" placeholder="0"
                       class="w-full px-2 py-2 rounded-lg border border-surface-200 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm text-surface-900 dark:text-white text-right focus:ring-2 focus:ring-primary-500 outline-none" />
                <div class="text-right text-sm font-medium text-surface-700 dark:text-surface-200 pr-1">
                  {{ formatMoney((item.quantity || 0) * (item.unit_price || 0)) }}
                </div>
                <button @click="removeItem(idx)" v-if="form.items.length > 1"
                        class="p-1 rounded-lg text-surface-300 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-500/10 opacity-0 group-hover:opacity-100 transition-all justify-self-center">
                  <span class="material-symbols-rounded text-lg">close</span>
                </button>
              </div>
            </div>
          </div>

          <!-- ============ SECTION: Pricing & Totals ============ -->
          <div>
            <div class="flex items-center gap-2 mb-3">
              <span class="material-symbols-rounded text-sm text-surface-400">payments</span>
              <span class="text-xs font-semibold uppercase tracking-wider text-surface-400">Pricing</span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="space-y-3">
                <div class="grid grid-cols-3 gap-3">
                  <div>
                    <label class="block text-sm font-medium text-surface-600 dark:text-surface-300 mb-1.5">Currency</label>
                    <select v-model="form.currency"
                            class="w-full px-3 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                      <option value="HUF">HUF</option>
                      <option value="EUR">EUR</option>
                      <option value="USD">USD</option>
                      <option value="GBP">GBP</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-surface-600 dark:text-surface-300 mb-1.5">Tax %</label>
                    <input v-model.number="form.tax_rate" type="number" min="0" max="100" step="0.5"
                           class="w-full px-3 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-surface-600 dark:text-surface-300 mb-1.5">Discount</label>
                    <input v-model.number="form.discount_amount" type="number" min="0"
                           class="w-full px-3 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
                  </div>
                </div>
              </div>
              <!-- Totals card -->
              <div class="bg-surface-50 dark:bg-surface-700/50 rounded-xl p-4 space-y-2">
                <div class="flex justify-between text-sm">
                  <span class="text-surface-500">Subtotal</span>
                  <span class="font-medium text-surface-700 dark:text-surface-200">{{ formatMoney(subtotal) }} {{ form.currency }}</span>
                </div>
                <div v-if="form.tax_rate > 0" class="flex justify-between text-sm">
                  <span class="text-surface-500">Tax ({{ form.tax_rate }}%)</span>
                  <span class="font-medium text-surface-700 dark:text-surface-200">{{ formatMoney(taxAmount) }} {{ form.currency }}</span>
                </div>
                <div v-if="form.discount_amount > 0" class="flex justify-between text-sm">
                  <span class="text-surface-500">Discount</span>
                  <span class="font-medium text-red-500">-{{ formatMoney(form.discount_amount) }} {{ form.currency }}</span>
                </div>
                <div class="flex justify-between text-base font-bold border-t border-surface-200 dark:border-surface-600 pt-2 mt-1">
                  <span class="text-surface-900 dark:text-white">Total</span>
                  <span class="text-primary-600 dark:text-primary-400">{{ formatMoney(total) }} {{ form.currency }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- ============ SECTION: Notes ============ -->
          <div>
            <div class="flex items-center gap-2 mb-3">
              <span class="material-symbols-rounded text-sm text-surface-400">sticky_note_2</span>
              <span class="text-xs font-semibold uppercase tracking-wider text-surface-400">Notes</span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-surface-600 dark:text-surface-300 mb-1.5">Visible on invoice</label>
                <textarea v-model="form.notes" rows="3" placeholder="Notes shown to client..."
                          class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none resize-none"></textarea>
              </div>
              <div>
                <label class="block text-sm font-medium text-surface-600 dark:text-surface-300 mb-1.5">Internal only</label>
                <textarea v-model="form.internal_notes" rows="3" placeholder="Private notes..."
                          class="w-full px-4 py-2.5 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none resize-none"></textarea>
              </div>
            </div>
          </div>

          <!-- ============ SECTION: Options ============ -->
          <div>
            <div class="flex items-center gap-2 mb-3">
              <span class="material-symbols-rounded text-sm text-surface-400">settings</span>
              <span class="text-xs font-semibold uppercase tracking-wider text-surface-400">Options</span>
            </div>
            <div class="p-4 bg-surface-50 dark:bg-surface-700/50 rounded-xl space-y-4">
              <!-- Recurring toggle -->
              <div class="flex items-center justify-between">
                <div>
                  <span class="text-sm font-medium text-surface-700 dark:text-surface-200">Recurring Invoice</span>
                  <p class="text-xs text-surface-400 mt-0.5">Automatically create this invoice on a schedule</p>
                </div>
                <button @click="form.is_recurring = !form.is_recurring" type="button"
                        :class="[
                          'relative inline-flex h-6 w-11 items-center rounded-full transition-colors duration-200 focus:outline-none',
                          form.is_recurring ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                        ]">
                  <span :class="[
                    'inline-block h-4 w-4 transform rounded-full bg-white shadow-sm transition-transform duration-200',
                    form.is_recurring ? 'translate-x-6' : 'translate-x-1'
                  ]" />
                </button>
              </div>

              <!-- Recurring options (slide open) -->
              <Transition
                enter-active-class="transition-all duration-200 ease-out"
                enter-from-class="opacity-0 max-h-0"
                enter-to-class="opacity-100 max-h-24"
                leave-active-class="transition-all duration-150 ease-in"
                leave-from-class="opacity-100 max-h-24"
                leave-to-class="opacity-0 max-h-0">
                <div v-if="form.is_recurring" class="flex items-center gap-3 pt-2 border-t border-surface-200 dark:border-surface-600 overflow-hidden">
                  <div>
                    <label class="block text-xs font-medium text-surface-500 mb-1">Interval</label>
                    <select v-model="form.recurrence_interval"
                            class="px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none">
                      <option value="weekly">Weekly</option>
                      <option value="monthly">Monthly</option>
                      <option value="quarterly">Quarterly</option>
                      <option value="yearly">Yearly</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-surface-500 mb-1">End Date (optional)</label>
                    <input v-model="form.recurrence_end_date" type="date"
                           class="px-3 py-2 rounded-xl border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
                  </div>
                </div>
              </Transition>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="flex justify-end gap-3 p-6 border-t border-surface-200 dark:border-surface-700">
          <button @click="emit('close')"
                  class="px-5 py-2.5 rounded-full text-sm font-medium text-surface-600 dark:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors">
            Cancel
          </button>
          <button @click="save" :disabled="saving"
                  class="px-6 py-2.5 rounded-full bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium disabled:opacity-50 shadow-sm transition-colors flex items-center gap-2">
            <span v-if="saving" class="material-symbols-rounded text-sm animate-spin">progress_activity</span>
            {{ saving ? 'Saving...' : isEditing ? 'Update Invoice' : 'Create Invoice' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
