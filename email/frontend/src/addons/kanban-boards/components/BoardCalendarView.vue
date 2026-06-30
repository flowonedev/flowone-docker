<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'

const emit = defineEmits(['open-card'])

const boardsStore = useBoardsStore()

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

// State
const currentDate = ref(new Date())
const viewMode = ref('month') // 'month', 'week', 'day'

// Computed
const lists = computed(() => boardsStore.currentLists || [])

const allCards = computed(() => {
  return lists.value.flatMap(list => 
    (list.cards || [])
      .filter(card => !card.parent_card_id)
      .filter(card => card.due_date)
      .map(card => ({
        ...card,
        list_name: list.name,
        list_id: list.id
      }))
  )
})

// Milestones - lists with invoice_date set, with progress tracking
const milestones = computed(() => {
  return lists.value
    .filter(list => list.invoice_date && list.expected_amount)
    .map(list => {
      // Calculate progress from cards and their checklists
      const cards = (list.cards || []).filter(card => !card.parent_card_id)
      const totalCards = cards.length
      const completedCards = cards.filter(c => c.completed).length
      
      // Count all checklist items across all cards
      let totalTodos = 0
      let completedTodos = 0
      cards.forEach(card => {
        if (card.checklists) {
          card.checklists.forEach(checklist => {
            if (checklist.items) {
              totalTodos += checklist.items.length
              completedTodos += checklist.items.filter(item => item.completed).length
            }
          })
        }
      })
      
      // Calculate progress - prioritize todos if available
      let progressPercent = 0
      if (totalTodos > 0) {
        progressPercent = Math.round((completedTodos / totalTodos) * 100)
      } else if (totalCards > 0) {
        progressPercent = Math.round((completedCards / totalCards) * 100)
      }
      
      return {
        id: list.id,
        name: list.name,
        invoice_date: list.invoice_date,
        expected_amount: list.expected_amount,
        currency: list.currency || 'HUF',
        is_milestone: list.is_milestone,
        progress_percent: progressPercent,
        total_cards: totalCards,
        completed_cards: completedCards,
        total_todos: totalTodos,
        completed_todos: completedTodos
      }
    })
})

const currentMonth = computed(() => currentDate.value.getMonth())
const currentYear = computed(() => currentDate.value.getFullYear())

const monthName = computed(() => {
  return currentDate.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
})

const calendarDays = computed(() => {
  const year = currentYear.value
  const month = currentMonth.value
  
  // First day of month
  const firstDay = new Date(year, month, 1)
  const startDay = firstDay.getDay()
  
  // Last day of month
  const lastDay = new Date(year, month + 1, 0)
  const daysInMonth = lastDay.getDate()
  
  // Previous month days to show
  const prevMonthLastDay = new Date(year, month, 0)
  const prevMonthDays = prevMonthLastDay.getDate()
  
  const days = []
  
  // Previous month days
  for (let i = startDay - 1; i >= 0; i--) {
    const day = prevMonthDays - i
    const date = new Date(year, month - 1, day)
    days.push({
      date,
      day,
      isCurrentMonth: false,
      isToday: false,
      cards: getCardsForDate(date),
      milestones: getMilestonesForDate(date)
    })
  }
  
  // Current month days
  const today = new Date()
  for (let day = 1; day <= daysInMonth; day++) {
    const date = new Date(year, month, day)
    const isToday = date.toDateString() === today.toDateString()
    days.push({
      date,
      day,
      isCurrentMonth: true,
      isToday,
      cards: getCardsForDate(date),
      milestones: getMilestonesForDate(date)
    })
  }
  
  // Next month days to fill 6 rows
  const remaining = 42 - days.length
  for (let day = 1; day <= remaining; day++) {
    const date = new Date(year, month + 1, day)
    days.push({
      date,
      day,
      isCurrentMonth: false,
      isToday: false,
      cards: getCardsForDate(date),
      milestones: getMilestonesForDate(date)
    })
  }
  
  return days
})

const weekDays = computed(() => {
  const today = new Date(currentDate.value)
  const dayOfWeek = today.getDay()
  const startOfWeek = new Date(today)
  startOfWeek.setDate(today.getDate() - dayOfWeek)
  
  const days = []
  for (let i = 0; i < 7; i++) {
    const date = new Date(startOfWeek)
    date.setDate(startOfWeek.getDate() + i)
    const isToday = date.toDateString() === new Date().toDateString()
    days.push({
      date,
      day: date.getDate(),
      dayName: date.toLocaleDateString(undefined, { weekday: 'short' }),
      isToday,
      cards: getCardsForDate(date),
      milestones: getMilestonesForDate(date)
    })
  }
  
  return days
})

// Methods
function getCardsForDate(date) {
  const dateStr = date.toISOString().split('T')[0]
  return allCards.value.filter(card => {
    if (!card.due_date) return false
    const cardDate = new Date(card.due_date).toISOString().split('T')[0]
    return cardDate === dateStr
  })
}

function getMilestonesForDate(date) {
  const dateStr = date.toISOString().split('T')[0]
  return milestones.value.filter(m => m.invoice_date === dateStr)
}

function formatCurrency(amount, currency) {
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

function previousMonth() {
  const newDate = new Date(currentDate.value)
  newDate.setMonth(newDate.getMonth() - 1)
  currentDate.value = newDate
}

function nextMonth() {
  const newDate = new Date(currentDate.value)
  newDate.setMonth(newDate.getMonth() + 1)
  currentDate.value = newDate
}

function previousWeek() {
  const newDate = new Date(currentDate.value)
  newDate.setDate(newDate.getDate() - 7)
  currentDate.value = newDate
}

function nextWeek() {
  const newDate = new Date(currentDate.value)
  newDate.setDate(newDate.getDate() + 7)
  currentDate.value = newDate
}

function previousDay() {
  const newDate = new Date(currentDate.value)
  newDate.setDate(newDate.getDate() - 1)
  currentDate.value = newDate
}

function nextDay() {
  const newDate = new Date(currentDate.value)
  newDate.setDate(newDate.getDate() + 1)
  currentDate.value = newDate
}

function goToToday() {
  currentDate.value = new Date()
}

// Day view computed
const dayViewData = computed(() => {
  const date = new Date(currentDate.value)
  const isToday = date.toDateString() === new Date().toDateString()
  return {
    date,
    day: date.getDate(),
    dayName: date.toLocaleDateString(undefined, { weekday: 'long' }),
    monthName: date.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' }),
    isToday,
    cards: getCardsForDate(date),
    milestones: getMilestonesForDate(date)
  }
})

function openCard(card) {
  emit('open-card', card)
}

function getCardStatus(card) {
  if (card.completed) return 'complete'
  const now = new Date()
  const due = new Date(card.due_date)
  if (due < now) return 'overdue'
  return 'pending'
}

const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const dayNamesShort = ['S', 'M', 'T', 'W', 'T', 'F', 'S']
</script>

<template>
  <div class="h-full flex flex-col bg-white dark:bg-surface-900">
    <!-- Header -->
    <div class="px-3 md:px-4 py-2 md:py-3 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between gap-2">
      <div class="flex items-center gap-2 md:gap-4 min-w-0">
        <h2 class="text-sm md:text-lg font-semibold text-surface-900 dark:text-surface-100 truncate">
          {{ viewMode === 'day' ? dayViewData.monthName : monthName }}
        </h2>
        
        <div class="flex items-center gap-0.5 md:gap-1 flex-shrink-0">
          <button 
            @click="viewMode === 'month' ? previousMonth() : viewMode === 'week' ? previousWeek() : previousDay()"
            class="p-1 md:p-1.5 hover:bg-surface-100 dark:hover:bg-surface-800 rounded-lg transition-colors"
          >
            <span class="material-symbols-rounded text-xl md:text-2xl">chevron_left</span>
          </button>
          <button 
            @click="goToToday"
            class="px-2 md:px-3 py-1 text-xs md:text-sm font-medium text-primary-500 hover:bg-primary-500/10 rounded-lg transition-colors"
          >
            Today
          </button>
          <button 
            @click="viewMode === 'month' ? nextMonth() : viewMode === 'week' ? nextWeek() : nextDay()"
            class="p-1 md:p-1.5 hover:bg-surface-100 dark:hover:bg-surface-800 rounded-lg transition-colors"
          >
            <span class="material-symbols-rounded text-xl md:text-2xl">chevron_right</span>
          </button>
        </div>
      </div>
      
      <div class="flex items-center gap-0.5 md:gap-1 p-0.5 md:p-1 bg-surface-100 dark:bg-surface-800 rounded-lg flex-shrink-0">
        <button 
          @click="viewMode = 'day'"
          :class="[
            'px-2 md:px-3 py-1 text-xs md:text-sm font-medium rounded-md transition-colors',
            viewMode === 'day' 
              ? 'bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 shadow-sm' 
              : 'text-surface-600 dark:text-surface-400'
          ]"
        >
          {{ isMobile ? 'D' : 'Day' }}
        </button>
        <button 
          @click="viewMode = 'week'"
          :class="[
            'px-2 md:px-3 py-1 text-xs md:text-sm font-medium rounded-md transition-colors',
            viewMode === 'week' 
              ? 'bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 shadow-sm' 
              : 'text-surface-600 dark:text-surface-400'
          ]"
        >
          {{ isMobile ? 'W' : 'Week' }}
        </button>
        <button 
          @click="viewMode = 'month'"
          :class="[
            'px-2 md:px-3 py-1 text-xs md:text-sm font-medium rounded-md transition-colors',
            viewMode === 'month' 
              ? 'bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 shadow-sm' 
              : 'text-surface-600 dark:text-surface-400'
          ]"
        >
          {{ isMobile ? 'M' : 'Month' }}
        </button>
      </div>
    </div>
    
    <!-- Month view -->
    <div v-if="viewMode === 'month'" class="flex-1 overflow-auto">
      <!-- Day headers -->
      <div class="grid grid-cols-7 border-b border-surface-200 dark:border-surface-700">
        <div 
          v-for="(day, i) in dayNames"
          :key="day"
          class="px-1 md:px-2 py-1.5 md:py-2 text-[10px] md:text-xs font-semibold text-surface-500 uppercase tracking-wide text-center"
        >
          {{ isMobile ? dayNamesShort[i] : day }}
        </div>
      </div>
      
      <!-- Calendar grid -->
      <div class="grid grid-cols-7 flex-1">
        <div
          v-for="(day, index) in calendarDays"
          :key="index"
          :class="[
            'border-b border-r border-surface-100 dark:border-surface-700 p-0.5 md:p-1',
            isMobile ? 'min-h-[60px]' : 'min-h-[120px]',
            !day.isCurrentMonth ? 'bg-surface-50 dark:bg-surface-800/50' : '',
            day.isToday ? 'bg-primary-500/5' : ''
          ]"
        >
          <!-- Day number -->
          <div class="flex items-center justify-between px-0.5 md:px-1 mb-0.5 md:mb-1">
            <span 
              :class="[
                'text-xs md:text-sm font-medium flex items-center justify-center rounded-full',
                isMobile ? 'w-5 h-5' : 'w-6 h-6',
                day.isToday ? 'bg-primary-500 text-white' : 
                day.isCurrentMonth ? 'text-surface-900 dark:text-surface-100' : 'text-surface-400'
              ]"
            >
              {{ day.day }}
            </span>
            <!-- Milestone indicator -->
            <span 
              v-if="day.milestones?.length > 0"
              class="material-symbols-rounded text-xs md:text-sm text-amber-500"
            >
              payments
            </span>
          </div>
          
          <!-- Mobile: Show colored dots instead of full cards -->
          <div v-if="isMobile" class="flex flex-wrap gap-0.5 justify-center px-0.5">
            <!-- Milestone dots -->
            <span 
              v-for="milestone in day.milestones?.slice(0, 2)"
              :key="'m-' + milestone.id"
              class="w-2 h-2 rounded-full bg-amber-500"
              :title="milestone.name"
            ></span>
            <!-- Card dots -->
            <span 
              v-for="card in day.cards.slice(0, 3)"
              :key="card.id"
              @click="openCard(card)"
              :class="[
                'w-2 h-2 rounded-full cursor-pointer',
                getCardStatus(card) === 'complete' ? 'bg-green-500' :
                getCardStatus(card) === 'overdue' ? 'bg-red-500' : 'bg-primary-500'
              ]"
              :title="card.title"
            ></span>
            <span 
              v-if="day.cards.length > 3 || (day.milestones?.length || 0) > 2"
              class="text-[8px] text-surface-400"
            >
              +{{ (day.cards.length > 3 ? day.cards.length - 3 : 0) + ((day.milestones?.length || 0) > 2 ? (day.milestones?.length || 0) - 2 : 0) }}
            </span>
          </div>
          
          <!-- Desktop: Show full card details -->
          <template v-else>
            <!-- Milestones for this day with hover tooltip -->
            <div 
              v-for="milestone in day.milestones?.slice(0, 1)"
              :key="'m-' + milestone.id"
              class="relative group"
            >
              <div 
                class="px-1.5 py-0.5 mb-1 text-xs rounded bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 truncate cursor-pointer hover:bg-amber-200 dark:hover:bg-amber-900/50 transition-colors flex items-center gap-1"
              >
                <span class="material-symbols-rounded text-xs">payments</span>
                {{ milestone.name }}
              </div>
              
              <!-- Hover tooltip -->
              <div class="absolute left-0 top-full mt-1 px-3 py-2 bg-surface-800 text-white text-xs rounded-lg shadow-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-50 min-w-[180px] pointer-events-none">
                <div class="font-medium mb-1">{{ milestone.name }}</div>
                <div class="text-amber-300 font-semibold">{{ formatCurrency(milestone.expected_amount, milestone.currency) }}</div>
                
                <!-- Progress info -->
                <div class="mt-2 pt-2 border-t border-surface-700">
                  <div class="flex items-center justify-between mb-1">
                    <span class="text-surface-400">Progress</span>
                    <span :class="milestone.progress_percent >= 100 ? 'text-green-400' : 'text-white'">
                      {{ milestone.progress_percent }}%
                    </span>
                  </div>
                  <div class="h-1.5 bg-surface-700 rounded-full overflow-hidden">
                    <div 
                      class="h-full rounded-full transition-all"
                      :class="milestone.progress_percent >= 100 ? 'bg-green-500' : 'bg-amber-500'"
                      :style="{ width: `${milestone.progress_percent}%` }"
                    ></div>
                  </div>
                  <div class="text-surface-400 text-[10px] mt-1">
                    {{ milestone.total_todos > 0 
                      ? `${milestone.completed_todos}/${milestone.total_todos} todos` 
                      : `${milestone.completed_cards}/${milestone.total_cards} cards` 
                    }}
                  </div>
                </div>
                
                <div v-if="milestone.progress_percent >= 100" class="mt-2 text-green-400 flex items-center gap-1">
                  <span class="material-symbols-rounded text-sm">check_circle</span>
                  Ready to invoice
                </div>
              </div>
            </div>
            
            <!-- Cards for this day -->
            <div class="space-y-1">
              <div
                v-for="card in day.cards.slice(0, 3)"
                :key="card.id"
                @click="openCard(card)"
                :class="[
                  'px-1.5 py-0.5 rounded text-xs truncate cursor-pointer transition-colors',
                  getCardStatus(card) === 'complete' 
                    ? 'bg-green-500/20 text-green-700 dark:text-green-400 line-through' 
                    : getCardStatus(card) === 'overdue'
                      ? 'bg-red-500/20 text-red-700 dark:text-red-400'
                      : 'bg-primary-500/20 text-primary-700 dark:text-primary-400'
                ]"
                :title="card.title"
              >
                {{ card.title }}
              </div>
              <div 
                v-if="day.cards.length > 3"
                class="px-1.5 text-xs text-surface-500"
              >
                +{{ day.cards.length - 3 }} more
              </div>
            </div>
          </template>
        </div>
      </div>
    </div>
    
    <!-- Week view -->
    <div v-else-if="viewMode === 'week'" class="flex-1 overflow-auto">
      <!-- Horizontal scroll wrapper for mobile -->
      <div :class="isMobile ? 'overflow-x-auto' : ''">
        <div :class="isMobile ? 'min-w-[700px]' : ''" class="grid grid-cols-7 h-full">
          <div
            v-for="day in weekDays"
            :key="day.date.toISOString()"
            class="border-r border-surface-100 dark:border-surface-700 flex flex-col"
          >
            <!-- Day header -->
            <div 
              class="px-2 md:px-3 py-1.5 md:py-2 border-b border-surface-200 dark:border-surface-700 text-center"
              :class="{ 'bg-primary-500/10': day.isToday }"
            >
              <div class="text-[10px] md:text-xs text-surface-500 uppercase">{{ day.dayName }}</div>
              <div 
                :class="[
                  'text-base md:text-lg font-semibold mt-0.5 md:mt-1',
                  day.isToday ? 'text-primary-500' : 'text-surface-900 dark:text-surface-100'
                ]"
              >
                {{ day.day }}
              </div>
            </div>
            
            <!-- Cards & Milestones -->
            <div class="flex-1 p-1.5 md:p-2 space-y-1.5 md:space-y-2 overflow-y-auto">
              <!-- Milestones with hover tooltip -->
              <div
                v-for="milestone in day.milestones"
                :key="'m-' + milestone.id"
                class="relative group"
              >
                <div class="p-1.5 md:p-2 rounded-lg text-xs md:text-sm bg-amber-100 dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700 cursor-pointer hover:bg-amber-200 dark:hover:bg-amber-900/50 transition-colors">
                  <div class="flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400 mb-0.5 md:mb-1">
                    <span class="material-symbols-rounded text-xs md:text-sm">payments</span>
                    <span class="truncate">{{ milestone.name }}</span>
                  </div>
                  <div class="text-[10px] md:text-xs text-amber-600 dark:text-amber-500">
                    {{ formatCurrency(milestone.expected_amount, milestone.currency) }}
                  </div>
                  <!-- Progress bar -->
                  <div class="mt-1.5 md:mt-2 h-1 bg-amber-200 dark:bg-amber-800 rounded-full overflow-hidden">
                    <div 
                      class="h-full rounded-full transition-all"
                      :class="milestone.progress_percent >= 100 ? 'bg-green-500' : 'bg-amber-500'"
                      :style="{ width: `${milestone.progress_percent}%` }"
                    ></div>
                  </div>
                </div>
                
                <!-- Hover tooltip (hidden on mobile) -->
                <div v-if="!isMobile" class="absolute left-full top-0 ml-2 px-3 py-2 bg-surface-800 text-white text-xs rounded-lg shadow-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-50 min-w-[180px] pointer-events-none">
                  <div class="font-medium mb-1">{{ milestone.name }}</div>
                  <div class="text-amber-300 font-semibold">{{ formatCurrency(milestone.expected_amount, milestone.currency) }}</div>
                  
                  <!-- Progress info -->
                  <div class="mt-2 pt-2 border-t border-surface-700">
                    <div class="flex items-center justify-between mb-1">
                      <span class="text-surface-400">Progress</span>
                      <span :class="milestone.progress_percent >= 100 ? 'text-green-400' : 'text-white'">
                        {{ milestone.progress_percent }}%
                      </span>
                    </div>
                    <div class="h-1.5 bg-surface-700 rounded-full overflow-hidden">
                      <div 
                        class="h-full rounded-full transition-all"
                        :class="milestone.progress_percent >= 100 ? 'bg-green-500' : 'bg-amber-500'"
                        :style="{ width: `${milestone.progress_percent}%` }"
                      ></div>
                    </div>
                    <div class="text-surface-400 text-[10px] mt-1">
                      {{ milestone.total_todos > 0 
                        ? `${milestone.completed_todos}/${milestone.total_todos} todos` 
                        : `${milestone.completed_cards}/${milestone.total_cards} cards` 
                      }}
                    </div>
                  </div>
                  
                  <div v-if="milestone.progress_percent >= 100" class="mt-2 text-green-400 flex items-center gap-1">
                    <span class="material-symbols-rounded text-sm">check_circle</span>
                    Ready to invoice
                  </div>
                </div>
              </div>
              
              <!-- Cards -->
              <div
                v-for="card in day.cards"
                :key="card.id"
                @click="openCard(card)"
                :class="[
                  'p-1.5 md:p-2 rounded-lg text-xs md:text-sm cursor-pointer transition-colors',
                  getCardStatus(card) === 'complete' 
                    ? 'bg-green-500/10 border border-green-500/30' 
                    : getCardStatus(card) === 'overdue'
                      ? 'bg-red-500/10 border border-red-500/30'
                      : 'bg-surface-100 dark:bg-surface-800 border border-surface-200 dark:border-surface-700 hover:border-primary-500'
                ]"
              >
                <div 
                  class="font-medium text-surface-900 dark:text-surface-100 mb-0.5 md:mb-1 truncate"
                  :class="{ 'line-through opacity-60': card.completed }"
                >
                  {{ card.title }}
                </div>
                <div class="text-[10px] md:text-xs text-surface-500 truncate">
                  {{ card.list_name }}
                </div>
                <div v-if="card.labels?.length" class="flex gap-0.5 md:gap-1 mt-1.5 md:mt-2">
                  <span
                    v-for="label in card.labels.slice(0, 3)"
                    :key="label.id"
                    class="w-3 md:w-4 h-1 md:h-1.5 rounded-full"
                    :style="{ backgroundColor: label.color }"
                  ></span>
                </div>
              </div>
              
              <div v-if="day.cards.length === 0 && day.milestones?.length === 0" class="text-center text-surface-400 text-[10px] md:text-xs py-2 md:py-4">
                No cards
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Day view -->
    <div v-else class="flex-1 overflow-auto p-3 md:p-4">
      <!-- Day header -->
      <div 
        class="mb-4 text-center"
        :class="{ 'bg-primary-500/10 rounded-xl py-3': dayViewData.isToday }"
      >
        <div class="text-sm text-surface-500 dark:text-surface-400">
          {{ dayViewData.dayName }}
        </div>
        <div 
          :class="[
            'text-3xl font-bold mt-1',
            dayViewData.isToday ? 'text-primary-500' : 'text-surface-900 dark:text-surface-100'
          ]"
        >
          {{ dayViewData.day }}
        </div>
      </div>
      
      <!-- Milestones for the day -->
      <div v-if="dayViewData.milestones?.length > 0" class="mb-4">
        <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2">Milestones</h3>
        <div class="space-y-2">
          <div 
            v-for="milestone in dayViewData.milestones"
            :key="'m-' + milestone.id"
            class="p-3 rounded-xl bg-amber-100 dark:bg-amber-900/30 border border-amber-300 dark:border-amber-700"
          >
            <div class="flex items-center gap-2 font-medium text-amber-700 dark:text-amber-400 mb-1">
              <span class="material-symbols-rounded text-sm">payments</span>
              {{ milestone.name }}
            </div>
            <div class="text-sm text-amber-600 dark:text-amber-500 font-semibold">
              {{ formatCurrency(milestone.expected_amount, milestone.currency) }}
            </div>
            <!-- Progress bar -->
            <div class="mt-2">
              <div class="flex items-center justify-between text-xs text-amber-600 dark:text-amber-500 mb-1">
                <span>Progress</span>
                <span>{{ milestone.progress_percent }}%</span>
              </div>
              <div class="h-1.5 bg-amber-200 dark:bg-amber-800 rounded-full overflow-hidden">
                <div 
                  class="h-full rounded-full transition-all"
                  :class="milestone.progress_percent >= 100 ? 'bg-green-500' : 'bg-amber-500'"
                  :style="{ width: `${milestone.progress_percent}%` }"
                ></div>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Cards for the day -->
      <div v-if="dayViewData.cards.length > 0">
        <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2">Tasks Due</h3>
        <div class="space-y-2">
          <div
            v-for="card in dayViewData.cards"
            :key="card.id"
            @click="openCard(card)"
            :class="[
              'p-3 rounded-xl cursor-pointer transition-colors active:scale-[0.98]',
              getCardStatus(card) === 'complete' 
                ? 'bg-green-500/10 border border-green-500/30' 
                : getCardStatus(card) === 'overdue'
                  ? 'bg-red-500/10 border border-red-500/30'
                  : 'bg-white dark:bg-surface-800 border border-surface-200 dark:border-surface-700'
            ]"
          >
            <div class="flex items-start gap-3">
              <!-- Checkbox indicator -->
              <div 
                :class="[
                  'w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0 mt-0.5',
                  card.completed 
                    ? 'bg-green-500 border-green-500 text-white' 
                    : getCardStatus(card) === 'overdue'
                      ? 'border-red-500'
                      : 'border-surface-300 dark:border-surface-600'
                ]"
              >
                <span v-if="card.completed" class="material-symbols-rounded text-sm">check</span>
              </div>
              
              <div class="flex-1 min-w-0">
                <div 
                  class="font-medium text-surface-900 dark:text-surface-100"
                  :class="{ 'line-through opacity-60': card.completed }"
                >
                  {{ card.title }}
                </div>
                <div class="text-sm text-surface-500 mt-0.5">
                  {{ card.list_name }}
                </div>
                
                <!-- Labels -->
                <div v-if="card.labels?.length" class="flex flex-wrap gap-1.5 mt-2">
                  <span
                    v-for="label in card.labels"
                    :key="label.id"
                    class="px-2 py-0.5 rounded-full text-xs text-white"
                    :style="{ backgroundColor: label.color }"
                  >
                    {{ label.name || 'Label' }}
                  </span>
                </div>
                
                <!-- Progress if has checklist -->
                <div v-if="card.checklist_total > 0" class="mt-2 flex items-center gap-2">
                  <div class="flex-1 h-1.5 bg-surface-200 dark:bg-surface-600 rounded-full overflow-hidden">
                    <div 
                      class="h-full bg-primary-500 rounded-full"
                      :style="{ width: (card.checklist_done / card.checklist_total * 100) + '%' }"
                    ></div>
                  </div>
                  <span class="text-xs text-surface-500">{{ card.checklist_done }}/{{ card.checklist_total }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Empty state -->
      <div v-if="dayViewData.cards.length === 0 && dayViewData.milestones?.length === 0" class="text-center py-12">
        <span class="material-symbols-rounded text-5xl text-surface-300 dark:text-surface-600">event_available</span>
        <p class="text-surface-500 mt-3">No tasks due on this day</p>
        <p class="text-sm text-surface-400 mt-1">Swipe or tap arrows to browse other days</p>
      </div>
    </div>
  </div>
</template>

