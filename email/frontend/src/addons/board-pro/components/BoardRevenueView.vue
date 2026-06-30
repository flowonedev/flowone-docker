<template>
  <div class="h-full flex flex-col bg-surface-50 dark:bg-surface-900 overflow-hidden">
    <!-- Header with milestone totals (absorbed from base Financials view) -->
    <div class="p-3 md:p-6 border-b border-surface-200 dark:border-surface-700 bg-white dark:bg-surface-800">
      <div class="flex items-center justify-between mb-3 md:mb-4 gap-2">
        <h2 class="text-base md:text-xl font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
          <span class="material-symbols-rounded text-green-500 text-lg md:text-xl">payments</span>
          Revenue & Billing
        </h2>
        <div class="flex items-center gap-2">
          <button
            class="flex items-center gap-1 px-3 py-1.5 text-xs rounded-full bg-surface-100 dark:bg-surface-700 hover:bg-surface-200 dark:hover:bg-surface-600 transition-colors"
            @click="refresh"
          >
            <span class="material-symbols-rounded text-sm">refresh</span>
            <span class="hidden md:inline">Refresh</span>
          </button>
        </div>
      </div>

      <!-- Milestone totals row -->
      <div v-if="Object.keys(milestoneTotals).length > 0" class="flex gap-2 md:gap-4 overflow-x-auto pb-1 md:pb-0">
        <div 
          v-for="(amount, currency) in milestoneTotals" 
          :key="currency"
          class="px-3 md:px-4 py-2 md:py-3 bg-surface-100 dark:bg-surface-700 rounded-xl flex-shrink-0"
        >
          <div class="text-xs md:text-sm text-surface-500 dark:text-surface-400">Milestones ({{ currency }})</div>
          <div class="text-lg md:text-2xl font-bold text-surface-900 dark:text-surface-100 whitespace-nowrap">
            {{ formatCurrency(amount, currency) }}
          </div>
        </div>
        <!-- Card-level revenue totals -->
        <div 
          v-for="(amount, currency) in cardRevenueTotals" 
          :key="'card-' + currency"
          class="px-3 md:px-4 py-2 md:py-3 bg-primary-50 dark:bg-primary-900/20 rounded-xl flex-shrink-0"
        >
          <div class="text-xs md:text-sm text-primary-500 dark:text-primary-400">Card Revenue ({{ currency }})</div>
          <div class="text-lg md:text-2xl font-bold text-primary-600 dark:text-primary-400 whitespace-nowrap">
            {{ formatCurrency(amount, currency) }}
          </div>
        </div>
      </div>
      <div v-else class="text-sm text-surface-500 dark:text-surface-400">
        No financial data yet. Set amounts on lists or cards to start tracking.
      </div>

      <!-- Payment terms -->
      <div v-if="paymentTerms" class="mt-2 md:mt-3 text-xs md:text-sm text-surface-500 dark:text-surface-400 flex items-center gap-2">
        <span class="material-symbols-rounded text-xs md:text-sm">schedule</span>
        Payment terms: {{ paymentTerms }} days
      </div>
    </div>

    <!-- Content -->
    <div class="flex-1 overflow-auto p-3 md:p-6">
      <div v-if="loading" class="flex items-center justify-center py-12">
        <span class="material-symbols-rounded animate-spin text-2xl text-surface-400">progress_activity</span>
      </div>

      <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-3 md:gap-6">
        <!-- Milestones (list-level billing) — absorbed from base Financials -->
        <div class="bg-white dark:bg-surface-800 rounded-xl shadow-sm border border-surface-200 dark:border-surface-700">
          <div class="px-3 md:px-4 py-2 md:py-3 border-b border-surface-200 dark:border-surface-700">
            <h3 class="text-sm md:text-base font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-amber-500">flag</span>
              Milestones
            </h3>
          </div>
          <div class="divide-y divide-surface-200 dark:divide-surface-700">
            <div 
              v-for="milestone in milestones" 
              :key="milestone.id"
              class="p-3 md:p-4 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
            >
              <div class="flex items-start justify-between mb-2">
                <div>
                  <div class="font-medium text-surface-900 dark:text-surface-100 flex items-center gap-2">
                    {{ milestone.name }}
                    <span v-if="milestone.is_milestone" class="material-symbols-rounded text-sm text-amber-500">flag</span>
                  </div>
                  <div class="text-lg font-semibold text-green-600 dark:text-green-400">
                    {{ formatCurrency(milestone.expected_amount, milestone.currency) }}
                  </div>
                </div>
                <span 
                  v-if="milestone.completion_percent >= 100"
                  class="text-xs px-2 py-0.5 rounded-full bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 font-medium"
                >Ready to invoice</span>
              </div>
              <!-- Progress -->
              <div class="mb-2">
                <div class="flex items-center justify-between text-xs text-surface-500 dark:text-surface-400 mb-1">
                  <span>Progress</span>
                  <span v-if="milestone.total_todos > 0">{{ milestone.completed_todos }}/{{ milestone.total_todos }} todos ({{ milestone.completion_percent }}%)</span>
                  <span v-else>{{ milestone.completed_cards }}/{{ milestone.total_cards }} tasks ({{ milestone.completion_percent }}%)</span>
                </div>
                <div class="h-2 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                  <div 
                    class="h-full rounded-full transition-all"
                    :class="milestone.completion_percent >= 100 ? 'bg-green-500' : 'bg-primary-500'"
                    :style="{ width: `${milestone.completion_percent}%` }"
                  ></div>
                </div>
              </div>
              <!-- Dates -->
              <div class="flex items-center gap-4 text-xs text-surface-500 dark:text-surface-400">
                <div v-if="milestone.invoice_date" class="flex items-center gap-1">
                  <span class="material-symbols-rounded text-xs">receipt</span>
                  Invoice: {{ milestone.invoice_date }}
                </div>
                <div v-if="milestone.payment_date" class="flex items-center gap-1">
                  <span class="material-symbols-rounded text-xs">payments</span>
                  Due: {{ milestone.payment_date }}
                </div>
              </div>
            </div>
            <div v-if="milestones.length === 0" class="p-8 text-center text-surface-500 dark:text-surface-400">
              <span class="material-symbols-rounded text-3xl mb-2 block">account_balance_wallet</span>
              <p class="text-sm">No milestones yet</p>
              <p class="text-xs mt-1">Set amounts and invoice dates on lists from the base Financials view</p>
            </div>
          </div>
        </div>

        <!-- Card-level revenue breakdown (Board Pro data) -->
        <div class="bg-white dark:bg-surface-800 rounded-xl shadow-sm border border-surface-200 dark:border-surface-700">
          <div class="px-3 md:px-4 py-2 md:py-3 border-b border-surface-200 dark:border-surface-700">
            <h3 class="text-sm md:text-base font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
              <span class="material-symbols-rounded text-sm text-primary-500">credit_score</span>
              Card Revenue
            </h3>
          </div>

          <div v-if="data.length === 0" class="p-8 text-center text-surface-400">
            <span class="material-symbols-rounded text-3xl block mb-2">credit_score</span>
            <p class="text-sm">No card-level revenue data</p>
            <p class="text-xs text-surface-500 mt-1">Open a card and set revenue / cost to see per-task breakdown</p>
          </div>

          <div v-else class="divide-y divide-surface-200 dark:divide-surface-700">
            <div
              v-for="list in data"
              :key="list.list_id"
            >
              <div class="px-4 py-2.5 bg-surface-50 dark:bg-surface-800/80 border-b border-surface-100 dark:border-surface-700 flex items-center justify-between">
                <h4 class="text-xs font-semibold text-surface-500 dark:text-surface-400 uppercase tracking-wide">{{ list.list_name }}</h4>
                <div class="flex gap-3">
                  <span
                    v-for="(tot, currency) in list.totals"
                    :key="currency"
                    class="text-xs font-medium text-surface-500"
                  >
                    {{ currency }}: {{ formatMoney(tot.revenue) }}
                  </span>
                </div>
              </div>
              <div class="divide-y divide-surface-100 dark:divide-surface-700">
                <div
                  v-for="card in list.cards"
                  :key="card.card_id"
                  class="px-4 py-2.5 flex items-center gap-3 hover:bg-surface-50 dark:hover:bg-surface-700/30 transition-colors"
                >
                  <div class="flex-1 min-w-0">
                    <p class="text-sm text-surface-800 dark:text-surface-200 truncate">{{ card.title }}</p>
                    <p v-if="card.assigned_to" class="text-xs text-surface-400 truncate">{{ card.assigned_to }}</p>
                  </div>
                  <div class="text-right shrink-0">
                    <p class="text-sm font-medium text-surface-800 dark:text-surface-200">
                      {{ formatMoney(card.revenue) }} {{ card.currency }}
                    </p>
                    <p class="text-xs" :class="card.margin >= 0 ? 'text-green-500' : 'text-red-500'">
                      Margin: {{ formatMoney(card.margin) }}
                    </p>
                  </div>
                  <span
                    v-if="crmProEnabled && card.invoice_status"
                    class="text-xs px-2 py-0.5 rounded-full shrink-0"
                    :class="invoiceClass(card.invoice_status)"
                  >
                    {{ card.invoice_status }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useBoardProStore } from '../stores/boardPro'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useClientsStore } from '@/stores/clients'
import { useAddons } from '@/composables/useAddons'

const store = useBoardProStore()
const boardsStore = useBoardsStore()
const clientsStore = useClientsStore()
const { crmProEnabled } = useAddons()


const loading = computed(() => store.lensViewLoading)
const data = computed(() => store.revenueViewData)

// ----- Milestone data (absorbed from base BoardFinancialsView) -----
const lists = computed(() => boardsStore.currentLists || [])
const board = computed(() => boardsStore.currentBoard)

const paymentTerms = computed(() => {
  if (board.value?.client_id) {
    const client = clientsStore.clients.find(c => c.id === board.value.client_id)
    if (client?.payment_terms_days) return client.payment_terms_days
  }
  return board.value?.payment_terms_days || 30
})

const milestones = computed(() => {
  return lists.value
    .filter(list => list.expected_amount && parseFloat(list.expected_amount) > 0)
    .map(list => {
      const totalCards = list.cards?.length || 0
      const completedCards = list.cards?.filter(c => c.completed).length || 0
      let totalTodos = 0
      let completedTodos = 0
      list.cards?.forEach(card => {
        totalTodos += card.checklist_total || 0
        completedTodos += card.checklist_done || 0
      })
      let completionPercent = 0
      if (totalTodos > 0) completionPercent = Math.round((completedTodos / totalTodos) * 100)
      else if (totalCards > 0) completionPercent = Math.round((completedCards / totalCards) * 100)

      let paymentDate = null
      if (list.invoice_date) {
        const d = new Date(list.invoice_date)
        d.setDate(d.getDate() + paymentTerms.value)
        paymentDate = d.toISOString().split('T')[0]
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
      if (a.invoice_date && b.invoice_date) return new Date(a.invoice_date) - new Date(b.invoice_date)
      if (a.invoice_date) return -1
      if (b.invoice_date) return 1
      return a.position - b.position
    })
})

const milestoneTotals = computed(() => {
  const totals = {}
  milestones.value.forEach(m => {
    const c = m.currency || 'HUF'
    totals[c] = (totals[c] || 0) + parseFloat(m.expected_amount || 0)
  })
  return totals
})

// Card-level revenue totals (from Board Pro data)
const cardRevenueTotals = computed(() => {
  const totals = {}
  data.value.forEach(list => {
    if (list.totals) {
      Object.entries(list.totals).forEach(([currency, vals]) => {
        totals[currency] = (totals[currency] || 0) + (vals.revenue || 0)
      })
    }
  })
  return totals
})

// ----- Formatters -----
function formatMoney(value) {
  if (value === null || value === undefined) return '-'
  return new Intl.NumberFormat(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(value)
}

function formatCurrency(amount, currency = 'HUF') {
  if (currency === 'HUF') {
    const formatted = new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(amount)
    return `${formatted} Ft`
  }
  return new Intl.NumberFormat('en-US', { style: 'currency', currency, minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount)
}

function invoiceClass(status) {
  switch (status) {
    case 'paid': return 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
    case 'sent': return 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-400'
    case 'draft': return 'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400'
    case 'overdue': return 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400'
    default: return 'bg-surface-100 dark:bg-surface-700 text-surface-500 dark:text-surface-400'
  }
}

function refresh() {
  const boardId = boardsStore.currentBoard?.id
  if (boardId) store.fetchRevenueView(boardId)
}

onMounted(() => {
  const boardId = boardsStore.currentBoard?.id
  if (boardId) store.fetchRevenueView(boardId)
})
</script>

