<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useClientsStore } from '@/stores/clients'
import { useToastStore } from '@/stores/toast'
import api from '@/services/api'

const emit = defineEmits(['open-card'])

const boardsStore = useBoardsStore()
const clientsStore = useClientsStore()
const toast = useToastStore()

// Mobile detection
const isMobile = ref(false)

function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

onMounted(() => {
  checkMobile()
  window.addEventListener('resize', checkMobile)
})

onUnmounted(() => {
  window.removeEventListener('resize', checkMobile)
})

// Available currencies
const availableCurrencies = ['HUF', 'EUR', 'USD', 'RON']

// State
const loading = ref(false)
const editingListId = ref(null)
const editForm = ref({
  expected_amount: '',
  currency: 'HUF',
  invoice_date: '',
  is_milestone: false,
  payment_status: 'unpaid'
})

// Add new milestone
const showAddMilestone = ref(false)
const newMilestone = ref({
  name: '',
  expected_amount: '',
  currency: 'HUF',
  invoice_date: '',
  is_milestone: true
})

// Computed
const lists = computed(() => boardsStore.currentLists || [])
const board = computed(() => boardsStore.currentBoard)

// Get client's payment terms (default 30 days)
const paymentTerms = computed(() => {
  if (board.value?.client_id) {
    const client = clientsStore.clients.find(c => c.id === board.value.client_id)
    if (client?.payment_terms_days) {
      return client.payment_terms_days
    }
  }
  return board.value?.payment_terms_days || 30
})

// Milestones with financial data
const milestones = computed(() => {
  return lists.value
    .filter(list => list.expected_amount && parseFloat(list.expected_amount) > 0)
    .map(list => {
      const visibleCards = (list.cards || []).filter(c => !c.parent_card_id)
      const totalCards = visibleCards.length
      const completedCards = visibleCards.filter(c => c.completed).length || 0
      
      // Count todos from all cards using checklist_total and checklist_done
      let totalTodos = 0
      let completedTodos = 0
      visibleCards.forEach(card => {
        totalTodos += card.checklist_total || 0
        completedTodos += card.checklist_done || 0
      })
      
      // Progress: prioritize todos if available, fall back to cards
      let completionPercent = 0
      if (totalTodos > 0) {
        completionPercent = Math.round((completedTodos / totalTodos) * 100)
      } else if (totalCards > 0) {
        completionPercent = Math.round((completedCards / totalCards) * 100)
      }
      
      let paymentDate = null
      if (list.invoice_date) {
        const invoiceDate = new Date(list.invoice_date)
        invoiceDate.setDate(invoiceDate.getDate() + paymentTerms.value)
        paymentDate = invoiceDate.toISOString().split('T')[0]
      }
      
      return {
        ...list,
        completion_percent: completionPercent,
        total_cards: totalCards,
        completed_cards: completedCards,
        total_todos: totalTodos,
        completed_todos: completedTodos,
        payment_date: paymentDate,
        currency: list.currency || 'HUF'
      }
    })
    .sort((a, b) => {
      // Sort by invoice date, then by position
      if (a.invoice_date && b.invoice_date) {
        return new Date(a.invoice_date) - new Date(b.invoice_date)
      }
      if (a.invoice_date) return -1
      if (b.invoice_date) return 1
      return a.position - b.position
    })
})

// Totals by currency
const totalsByCurrency = computed(() => {
  const totals = {}
  milestones.value.forEach(m => {
    const curr = m.currency || 'HUF'
    totals[curr] = (totals[curr] || 0) + parseFloat(m.expected_amount || 0)
  })
  return totals
})

const paidTotalsByCurrency = computed(() => {
  const totals = {}
  milestones.value.filter(m => m.payment_status === 'paid').forEach(m => {
    const curr = m.currency || 'HUF'
    totals[curr] = (totals[curr] || 0) + parseFloat(m.expected_amount || 0)
  })
  return totals
})

const unpaidTotalsByCurrency = computed(() => {
  const totals = {}
  milestones.value.filter(m => m.payment_status !== 'paid').forEach(m => {
    const curr = m.currency || 'HUF'
    totals[curr] = (totals[curr] || 0) + parseFloat(m.expected_amount || 0)
  })
  return totals
})

const paidCount = computed(() => milestones.value.filter(m => m.payment_status === 'paid').length)
const unpaidCount = computed(() => milestones.value.filter(m => m.payment_status !== 'paid').length)

// Cash flow by month
const cashFlowByMonth = computed(() => {
  const flow = {}
  
  milestones.value.forEach(m => {
    if (m.payment_date) {
      const monthKey = m.payment_date.substring(0, 7) // YYYY-MM
      if (!flow[monthKey]) {
        flow[monthKey] = {}
      }
      const curr = m.currency || 'HUF'
      flow[monthKey][curr] = (flow[monthKey][curr] || 0) + parseFloat(m.expected_amount || 0)
    }
  })
  
  // Sort by month
  return Object.entries(flow)
    .sort((a, b) => a[0].localeCompare(b[0]))
    .map(([month, currencies]) => ({
      month,
      monthLabel: formatMonth(month),
      currencies
    }))
})

// Format month for display
function formatMonth(monthStr) {
  const [year, month] = monthStr.split('-')
  const date = new Date(year, parseInt(month) - 1)
  return date.toLocaleDateString(undefined, { year: 'numeric', month: 'long' })
}

// Format currency with proper locale (HUF uses space as thousand separator)
function formatCurrency(amount, currency = 'HUF') {
  // Use commas as thousand separator for all currencies
  if (currency === 'HUF') {
    const formatted = new Intl.NumberFormat('en-US', {
      minimumFractionDigits: 0,
      maximumFractionDigits: 0
    }).format(amount)
    return `${formatted} Ft`
  }
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(amount)
}

// Start editing a list's financial settings
function startEditing(list) {
  editingListId.value = list.id
  editForm.value = {
    expected_amount: list.expected_amount || '',
    currency: list.currency || 'HUF',
    invoice_date: list.invoice_date || '',
    is_milestone: list.is_milestone || false,
    payment_status: list.payment_status || 'unpaid'
  }
}

// Cancel editing
function cancelEditing() {
  editingListId.value = null
  editForm.value = {
    expected_amount: '',
    currency: 'HUF',
    invoice_date: '',
    is_milestone: false,
    payment_status: 'unpaid'
  }
}

// Save list settings
async function saveListSettings(listId) {
  const updates = {
    expected_amount: editForm.value.expected_amount ? parseFloat(editForm.value.expected_amount) : null,
    currency: editForm.value.currency || 'HUF',
    invoice_date: editForm.value.invoice_date || null,
    is_milestone: editForm.value.is_milestone,
    payment_status: editForm.value.payment_status || 'unpaid'
  }
  
  if (await boardsStore.updateList(listId, updates)) {
    toast.success('Milestone saved')
    editingListId.value = null
  } else {
    toast.error('Failed to save milestone')
  }
}

// Quick-toggle payment status without entering edit mode
async function togglePaymentStatus(milestone) {
  const newStatus = milestone.payment_status === 'paid' ? 'unpaid' : 'paid'
  if (await boardsStore.updateList(milestone.id, { payment_status: newStatus })) {
    toast.success(newStatus === 'paid' ? 'Marked as paid' : 'Marked as unpaid')
  } else {
    toast.error('Failed to update status')
  }
}

// Remove financial data from list
async function removeFinancials(listId) {
  const updates = {
    expected_amount: null,
    invoice_date: null,
    is_milestone: false
  }
  
  if (await boardsStore.updateList(listId, updates)) {
    toast.success('Financial data removed')
  }
}

// Add new milestone as a list
async function addMilestone() {
  if (!newMilestone.value.name.trim()) {
    toast.error('Please enter a milestone name')
    return
  }
  
  const listData = {
    name: newMilestone.value.name,
    expected_amount: newMilestone.value.expected_amount ? parseFloat(newMilestone.value.expected_amount) : null,
    currency: newMilestone.value.currency || 'HUF',
    invoice_date: newMilestone.value.invoice_date || null,
    is_milestone: true
  }
  
  if (await boardsStore.createList(board.value.id, listData)) {
    toast.success('Milestone created')
    showAddMilestone.value = false
    newMilestone.value = {
      name: '',
      expected_amount: '',
      currency: 'HUF',
      invoice_date: '',
      is_milestone: true
    }
  } else {
    toast.error('Failed to create milestone')
  }
}

// Get all lists that don't have financial data yet
const availableLists = computed(() => {
  return lists.value.filter(list => !list.expected_amount || parseFloat(list.expected_amount) <= 0)
})

// Add financial data to existing list
const showAddToList = ref(false)
const selectedListForFinancials = ref(null)

function openAddToList() {
  showAddToList.value = true
  selectedListForFinancials.value = null
  editForm.value = {
    expected_amount: '',
    currency: 'HUF',
    invoice_date: '',
    is_milestone: true
  }
}

async function addFinancialsToList() {
  if (!selectedListForFinancials.value) {
    toast.error('Please select a list')
    return
  }
  
  const updates = {
    expected_amount: editForm.value.expected_amount ? parseFloat(editForm.value.expected_amount) : null,
    currency: editForm.value.currency || 'HUF',
    invoice_date: editForm.value.invoice_date || null,
    is_milestone: editForm.value.is_milestone
  }
  
  if (await boardsStore.updateList(selectedListForFinancials.value, updates)) {
    toast.success('Financial data added')
    showAddToList.value = false
    selectedListForFinancials.value = null
  } else {
    toast.error('Failed to add financial data')
  }
}

// Open card modal
function openCard(card) {
  emit('open-card', card)
}
</script>

<template>
  <div class="h-full flex flex-col bg-surface-50 dark:bg-surface-900 overflow-hidden">
    <!-- Header with totals -->
    <div class="p-3 md:p-6 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800">
      <div class="flex items-center justify-between mb-3 md:mb-4 gap-2">
        <h2 class="text-base md:text-xl font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
          <span class="material-symbols-rounded text-green-500 text-lg md:text-xl">payments</span>
          <span class="hidden sm:inline">Milestones & Billing</span>
          <span class="sm:hidden">Billing</span>
        </h2>
        
        <div class="flex items-center gap-1 md:gap-2 flex-shrink-0">
          <!-- Add to existing list -->
          <button
            v-if="availableLists.length > 0"
            @click="openAddToList"
            :class="[
              'flex items-center gap-1 md:gap-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors',
              isMobile ? 'p-2' : 'px-4 py-2'
            ]"
            :title="isMobile ? 'Add to List' : ''"
          >
            <span class="material-symbols-rounded text-sm">playlist_add</span>
            <span class="hidden md:inline">Add to List</span>
          </button>
          
          <!-- Add new milestone -->
          <button
            @click="showAddMilestone = true"
            :class="[
              'flex items-center gap-1 md:gap-2 bg-primary-500 text-white rounded-full hover:bg-primary-600 transition-colors',
              isMobile ? 'p-2' : 'px-4 py-2'
            ]"
            :title="isMobile ? 'New Milestone' : ''"
          >
            <span class="material-symbols-rounded text-sm">add</span>
            <span class="hidden md:inline">New Milestone</span>
          </button>
        </div>
      </div>
      
      <!-- Currency totals - horizontal scroll on mobile -->
      <div class="flex gap-2 md:gap-4 overflow-x-auto pb-1 md:pb-0">
        <div 
          v-for="(amount, currency) in totalsByCurrency" 
          :key="currency"
          class="px-3 md:px-4 py-2 md:py-3 bg-surface-100 dark:bg-surface-700 rounded-xl flex-shrink-0"
        >
          <div class="text-xs md:text-sm text-surface-500 dark:text-surface-400">{{ currency }} Total</div>
          <div class="text-lg md:text-2xl font-bold text-surface-900 dark:text-surface-100 whitespace-nowrap">
            {{ formatCurrency(amount, currency) }}
          </div>
          <div class="flex items-center gap-3 mt-1">
            <span v-if="paidTotalsByCurrency[currency]" class="text-xs text-emerald-600 dark:text-emerald-400 flex items-center gap-0.5">
              <span class="material-symbols-rounded text-xs">paid</span>
              {{ formatCurrency(paidTotalsByCurrency[currency], currency) }}
            </span>
            <span v-if="unpaidTotalsByCurrency[currency]" class="text-xs text-amber-600 dark:text-amber-400 flex items-center gap-0.5">
              <span class="material-symbols-rounded text-xs">pending</span>
              {{ formatCurrency(unpaidTotalsByCurrency[currency], currency) }}
            </span>
          </div>
        </div>
        
        <div v-if="Object.keys(totalsByCurrency).length === 0" class="text-sm text-surface-500 dark:text-surface-400">
          No milestones with amounts set yet.
        </div>
      </div>
      
      <!-- Payment terms info -->
      <div class="mt-2 md:mt-3 text-xs md:text-sm text-surface-500 dark:text-surface-400 flex items-center gap-2">
        <span class="material-symbols-rounded text-xs md:text-sm">schedule</span>
        Payment terms: {{ paymentTerms }} days
      </div>
    </div>
    
    <!-- Main content -->
    <div class="flex-1 overflow-auto p-3 md:p-6">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 md:gap-6">
        <!-- Milestones Table -->
        <div class="bg-white dark:bg-surface-800 rounded-xl shadow-sm border border-surface-200 dark:border-surface-700">
          <div class="px-3 md:px-4 py-2 md:py-3 border-b border-surface-200 dark:border-surface-700">
            <h3 class="text-sm md:text-base font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-blue-500">flag</span>
              Milestones
            </h3>
          </div>
          
          <div class="divide-y divide-surface-200 dark:divide-surface-700">
            <div 
              v-for="milestone in milestones" 
              :key="milestone.id"
              class="p-3 md:p-4 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
            >
              <!-- Editing mode -->
              <div v-if="editingListId === milestone.id" class="space-y-3">
                <div class="flex items-center gap-2">
                  <select
                    v-model="editForm.currency"
                    class="px-2 py-1.5 text-sm border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
                  >
                    <option v-for="curr in availableCurrencies" :key="curr" :value="curr">{{ curr }}</option>
                  </select>
                  <input 
                    v-model="editForm.expected_amount"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="Amount"
                    class="flex-1 px-3 py-1.5 text-sm border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
                  />
                </div>
                
                <div class="flex items-center gap-2">
                  <input 
                    v-model="editForm.invoice_date"
                    type="date"
                    class="flex-1 px-3 py-1.5 text-sm border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
                  />
                  
                  <label class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-400">
                    <button
                      type="button"
                      @click="editForm.is_milestone = !editForm.is_milestone"
                      :class="[
                        'relative inline-flex h-5 w-9 items-center rounded-full transition-colors',
                        editForm.is_milestone ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                      ]"
                    >
                      <span
                        :class="[
                          'inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform',
                          editForm.is_milestone ? 'translate-x-5' : 'translate-x-1'
                        ]"
                      />
                    </button>
                    Milestone
                  </label>
                  
                  <label class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-400">
                    <button
                      type="button"
                      @click="editForm.payment_status = editForm.payment_status === 'paid' ? 'unpaid' : 'paid'"
                      :class="[
                        'relative inline-flex h-5 w-9 items-center rounded-full transition-colors',
                        editForm.payment_status === 'paid' ? 'bg-emerald-500' : 'bg-surface-300 dark:bg-surface-600'
                      ]"
                    >
                      <span
                        :class="[
                          'inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform',
                          editForm.payment_status === 'paid' ? 'translate-x-5' : 'translate-x-1'
                        ]"
                      />
                    </button>
                    Paid
                  </label>
                </div>
                
                <div class="flex justify-end gap-2">
                  <button 
                    @click="cancelEditing"
                    class="px-3 py-1.5 text-sm text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg"
                  >
                    Cancel
                  </button>
                  <button 
                    @click="saveListSettings(milestone.id)"
                    class="px-3 py-1.5 text-sm bg-primary-500 text-white rounded-lg hover:bg-primary-600"
                  >
                    Save
                  </button>
                </div>
              </div>
              
              <!-- Display mode -->
              <div v-else>
                <div class="flex items-start justify-between mb-2">
                  <div>
                    <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-2">
                      {{ milestone.name }}
                      <span v-if="milestone.is_milestone" class="material-symbols-rounded text-sm text-amber-500">flag</span>
                    </div>
                    <div class="text-lg font-semibold text-green-600 dark:text-green-400">
                      {{ formatCurrency(milestone.expected_amount, milestone.currency) }}
                    </div>
                    <button
                      @click.stop="togglePaymentStatus(milestone)"
                      :class="[
                        'mt-1 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium cursor-pointer transition-colors',
                        milestone.payment_status === 'paid'
                          ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400 hover:bg-emerald-200 dark:hover:bg-emerald-900/50'
                          : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 hover:bg-amber-200 dark:hover:bg-amber-900/50'
                      ]"
                      :title="milestone.payment_status === 'paid' ? 'Click to mark unpaid' : 'Click to mark paid'"
                    >
                      <span class="material-symbols-rounded text-sm">{{ milestone.payment_status === 'paid' ? 'paid' : 'pending' }}</span>
                      {{ milestone.payment_status === 'paid' ? 'Paid' : 'Unpaid' }}
                    </button>
                  </div>
                  
                  <div class="flex items-center gap-1">
                    <button 
                      @click="startEditing(milestone)"
                      class="p-1.5 text-surface-400 hover:text-surface-600 dark:hover:text-surface-300 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
                    >
                      <span class="material-symbols-rounded text-sm">edit</span>
                    </button>
                    <button 
                      @click="removeFinancials(milestone.id)"
                      class="p-1.5 text-surface-400 hover:text-red-500 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
                    >
                      <span class="material-symbols-rounded text-sm">delete</span>
                    </button>
                  </div>
                </div>
                
                <!-- Progress bar -->
                <div class="mb-2">
                  <div class="flex items-center justify-between text-xs text-surface-500 dark:text-surface-400 mb-1">
                    <span>Progress</span>
                    <span v-if="milestone.total_todos > 0">{{ milestone.completed_todos }}/{{ milestone.total_todos }} todos ({{ milestone.completion_percent }}%)</span>
                    <span v-else>{{ milestone.completed_cards }}/{{ milestone.total_cards }} tasks ({{ milestone.completion_percent }}%)</span>
                  </div>
                  <div class="h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                    <div 
                      class="h-full bg-primary-500 rounded-full transition-all"
                      :style="{ width: `${milestone.completion_percent}%` }"
                    ></div>
                  </div>
                </div>
                
                <!-- Dates -->
                <div class="flex items-center gap-4 text-sm text-surface-500 dark:text-surface-400">
                  <div v-if="milestone.invoice_date" class="flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm">receipt</span>
                    Invoice: {{ milestone.invoice_date }}
                  </div>
                  <div v-if="milestone.payment_date" class="flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm">payments</span>
                    Payment: {{ milestone.payment_date }}
                  </div>
                </div>
              </div>
            </div>
            
            <div v-if="milestones.length === 0" class="p-8 text-center text-surface-500 dark:text-surface-400">
              <span class="material-symbols-rounded text-4xl mb-2 block">account_balance_wallet</span>
              <p>No milestones set</p>
              <p class="text-sm">Add amounts and invoice dates to your lists to track billing</p>
            </div>
          </div>
        </div>
        
        <!-- Cash Flow Projection -->
        <div class="bg-white dark:bg-surface-800 rounded-xl shadow-sm border border-surface-200 dark:border-surface-700">
          <div class="px-3 md:px-4 py-2 md:py-3 border-b border-surface-200 dark:border-surface-700">
            <h3 class="text-sm md:text-base font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-green-500">trending_up</span>
              Cash Flow Projection
            </h3>
          </div>
          
          <div class="divide-y divide-surface-200 dark:divide-surface-700">
            <div 
              v-for="monthData in cashFlowByMonth" 
              :key="monthData.month"
              class="p-3 md:p-4"
            >
              <div class="font-medium text-surface-900 dark:text-surface-100 mb-2">
                {{ monthData.monthLabel }}
              </div>
              <div class="flex flex-wrap gap-3">
                <div 
                  v-for="(amount, currency) in monthData.currencies" 
                  :key="currency"
                  class="px-3 py-1.5 bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 rounded-full text-sm font-medium"
                >
                  {{ formatCurrency(amount, currency) }}
                </div>
              </div>
            </div>
            
            <div v-if="cashFlowByMonth.length === 0" class="p-8 text-center text-surface-500 dark:text-surface-400">
              <span class="material-symbols-rounded text-4xl mb-2 block">calendar_month</span>
              <p>No projected payments</p>
              <p class="text-sm">Set invoice dates on milestones to see when payments are expected</p>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Add New Milestone Modal -->
    <Teleport to="body">
      <Transition name="fade">
        <div 
          v-if="showAddMilestone" 
          class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
          @click.self="showAddMilestone = false"
        >
          <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-md p-6">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-green-500">add_circle</span>
              New Milestone
            </h3>
            
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
                  Milestone Name
                </label>
                <input 
                  v-model="newMilestone.name"
                  type="text"
                  placeholder="e.g., Phase 1 - Design"
                  class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                />
              </div>
              
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
                  Expected Amount
                </label>
                <div class="flex gap-2">
                  <select
                    v-model="newMilestone.currency"
                    class="px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
                  >
                    <option v-for="curr in availableCurrencies" :key="curr" :value="curr">{{ curr }}</option>
                  </select>
                  <input 
                    v-model="newMilestone.expected_amount"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="0.00"
                    class="flex-1 px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
                  />
                </div>
              </div>
              
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
                  Invoice Date
                </label>
                <input 
                  v-model="newMilestone.invoice_date"
                  type="date"
                  class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
                />
              </div>
            </div>
            
            <div class="flex justify-end gap-2 mt-6">
              <button 
                @click="showAddMilestone = false"
                class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              >
                Cancel
              </button>
              <button 
                @click="addMilestone"
                class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-colors"
              >
                Create Milestone
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
    
    <!-- Add to Existing List Modal -->
    <Teleport to="body">
      <Transition name="fade">
        <div 
          v-if="showAddToList" 
          class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
          @click.self="showAddToList = false"
        >
          <div class="bg-white dark:bg-surface-800 rounded-xl shadow-xl w-full max-w-md p-6">
            <h3 class="text-lg font-semibold text-surface-900 dark:text-surface-100 mb-4 flex items-center gap-2">
              <span class="material-symbols-rounded text-blue-500">playlist_add</span>
              Add Financials to List
            </h3>
            
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
                  Select List
                </label>
                <select
                  v-model="selectedListForFinancials"
                  class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
                >
                  <option :value="null" disabled>Choose a list...</option>
                  <option v-for="list in availableLists" :key="list.id" :value="list.id">
                    {{ list.name }}
                  </option>
                </select>
              </div>
              
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
                  Expected Amount
                </label>
                <div class="flex gap-2">
                  <select
                    v-model="editForm.currency"
                    class="px-3 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
                  >
                    <option v-for="curr in availableCurrencies" :key="curr" :value="curr">{{ curr }}</option>
                  </select>
                  <input 
                    v-model="editForm.expected_amount"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="0.00"
                    class="flex-1 px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
                  />
                </div>
              </div>
              
              <div>
                <label class="block text-sm font-medium text-surface-700 dark:text-surface-300 mb-1">
                  Invoice Date
                </label>
                <input 
                  v-model="editForm.invoice_date"
                  type="date"
                  class="w-full px-4 py-2 border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-900 text-surface-900 dark:text-surface-100"
                />
              </div>
              
              <div class="flex items-center gap-2">
                <button
                  type="button"
                  @click="editForm.is_milestone = !editForm.is_milestone"
                  :class="[
                    'relative inline-flex h-5 w-9 items-center rounded-full transition-colors',
                    editForm.is_milestone ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
                  ]"
                >
                  <span
                    :class="[
                      'inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform',
                      editForm.is_milestone ? 'translate-x-5' : 'translate-x-1'
                    ]"
                  />
                </button>
                <span class="text-sm text-surface-600 dark:text-surface-400">
                  Mark as Milestone
                </span>
              </div>
            </div>
            
            <div class="flex justify-end gap-2 mt-6">
              <button 
                @click="showAddToList = false"
                class="px-4 py-2 text-surface-600 dark:text-surface-400 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-lg transition-colors"
              >
                Cancel
              </button>
              <button 
                @click="addFinancialsToList"
                class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-colors"
              >
                Add Financials
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>

