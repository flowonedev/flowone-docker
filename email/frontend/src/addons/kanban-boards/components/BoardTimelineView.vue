<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useToastStore } from '@/stores/toast'

const emit = defineEmits(['open-card'])

const boardsStore = useBoardsStore()
const toast = useToastStore()

// Mobile detection
const isMobile = ref(false)

function checkMobile() {
  isMobile.value = window.innerWidth < 768
}

// Refs
const timelineContainer = ref(null)
const containerWidth = ref(800) // Default fallback

// State
const currentDate = ref(new Date())
const zoomLevel = ref('week') // 'day', 'week', 'month'
const showCompleted = ref(true)

// Update container width on resize
function updateContainerWidth() {
  if (timelineContainer.value) {
    // Subtract sidebar width (192px = w-48 on desktop, 128px = w-32 on mobile)
    const sidebarWidth = isMobile.value ? 128 : 192
    containerWidth.value = timelineContainer.value.clientWidth - sidebarWidth
  }
}

let resizeObserver = null

onMounted(() => {
  checkMobile()
  updateContainerWidth()
  window.addEventListener('resize', () => {
    checkMobile()
    updateContainerWidth()
  })
  
  // Use ResizeObserver for more accurate container tracking
  if (typeof ResizeObserver !== 'undefined' && timelineContainer.value) {
    resizeObserver = new ResizeObserver(updateContainerWidth)
    resizeObserver.observe(timelineContainer.value)
  }
})

onUnmounted(() => {
  window.removeEventListener('resize', updateContainerWidth)
  if (resizeObserver) {
    resizeObserver.disconnect()
  }
})

// Drag state
const draggingCard = ref(null)
const dragType = ref(null) // 'move', 'resize-start', 'resize-end'
const dragStartX = ref(0)
const dragStartDate = ref(null)
const dragEndDate = ref(null)

// Computed
const lists = computed(() => boardsStore.currentLists || [])

// Milestones - lists with invoice_date set
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
        invoice_date: new Date(list.invoice_date),
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

const allCards = computed(() => {
  return lists.value.flatMap(list => 
    (list.cards || [])
      .filter(card => !card.parent_card_id)
      .filter(card => card.due_date || card.start_date)
      .filter(card => showCompleted.value || !card.completed)
      .map(card => ({
        ...card,
        list_name: list.name,
        list_id: list.id,
        list_color: getListColor(list.id)
      }))
  )
})

// Get date range based on zoom level
const dateRange = computed(() => {
  const start = new Date(currentDate.value)
  const end = new Date(currentDate.value)
  
  switch (zoomLevel.value) {
    case 'day':
      start.setDate(start.getDate() - 3)
      end.setDate(end.getDate() + 11)
      break
    case 'week':
      start.setDate(start.getDate() - 7)
      end.setDate(end.getDate() + 28)
      break
    case 'month':
      start.setMonth(start.getMonth() - 1)
      end.setMonth(end.getMonth() + 3)
      break
  }
  
  return { start, end }
})

// Generate timeline columns
const timelineColumns = computed(() => {
  const columns = []
  const current = new Date(dateRange.value.start)
  
  while (current <= dateRange.value.end) {
    const isToday = current.toDateString() === new Date().toDateString()
    const isWeekend = current.getDay() === 0 || current.getDay() === 6
    
    columns.push({
      date: new Date(current),
      label: formatColumnLabel(current),
      isToday,
      isWeekend,
      isMonthStart: current.getDate() === 1
    })
    
    current.setDate(current.getDate() + 1)
  }
  
  return columns
})

// Minimum column width based on zoom
const minColumnWidth = computed(() => {
  switch (zoomLevel.value) {
    case 'day': return 60
    case 'week': return 30
    case 'month': return 12
    default: return 30
  }
})

// Column width - stretch to fill container, but respect minimum
const columnWidth = computed(() => {
  const numColumns = timelineColumns.value.length
  if (numColumns === 0) return minColumnWidth.value
  
  const calculatedWidth = containerWidth.value / numColumns
  return Math.max(calculatedWidth, minColumnWidth.value)
})

// Total width - always use full container width when columns fit, otherwise use calculated
const totalWidth = computed(() => {
  const numColumns = timelineColumns.value.length
  const naturalWidth = numColumns * minColumnWidth.value
  
  // If natural width fits in container, stretch to full width
  if (naturalWidth <= containerWidth.value) {
    return containerWidth.value
  }
  // Otherwise use the scrollable width
  return numColumns * columnWidth.value
})

// Cards with position data
const positionedCards = computed(() => {
  return allCards.value.map(card => {
    const startDate = card.start_date ? new Date(card.start_date) : new Date(card.due_date)
    const endDate = new Date(card.due_date)
    
    // If no start date, make the card 1 day long ending at due date
    if (!card.start_date) {
      startDate.setDate(endDate.getDate() - 1)
    }
    
    const rangeStart = dateRange.value.start.getTime()
    const startOffset = Math.max(0, (startDate.getTime() - rangeStart) / (1000 * 60 * 60 * 24))
    const duration = Math.max(1, (endDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24) + 1)
    
    return {
      ...card,
      startOffset,
      duration,
      left: startOffset * columnWidth.value,
      width: duration * columnWidth.value
    }
  })
})

// Group cards by list
const cardsByList = computed(() => {
  const grouped = {}
  lists.value.forEach(list => {
    grouped[list.id] = {
      list,
      cards: positionedCards.value.filter(c => c.list_id === list.id)
    }
  })
  return grouped
})

// Methods
function formatColumnLabel(date) {
  switch (zoomLevel.value) {
    case 'day':
      return date.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric' })
    case 'week':
      return date.getDate() === 1 || date.getDay() === 1
        ? date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
        : date.getDate().toString()
    case 'month':
      return date.getDate() === 1
        ? date.toLocaleDateString(undefined, { month: 'short' })
        : ''
    default:
      return date.getDate().toString()
  }
}

function getListColor(listId) {
  const colors = ['#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#ec4899']
  const index = lists.value.findIndex(l => l.id === listId)
  return colors[index % colors.length]
}

function previousPeriod() {
  const newDate = new Date(currentDate.value)
  switch (zoomLevel.value) {
    case 'day':
      newDate.setDate(newDate.getDate() - 7)
      break
    case 'week':
      newDate.setDate(newDate.getDate() - 14)
      break
    case 'month':
      newDate.setMonth(newDate.getMonth() - 1)
      break
  }
  currentDate.value = newDate
}

function nextPeriod() {
  const newDate = new Date(currentDate.value)
  switch (zoomLevel.value) {
    case 'day':
      newDate.setDate(newDate.getDate() + 7)
      break
    case 'week':
      newDate.setDate(newDate.getDate() + 14)
      break
    case 'month':
      newDate.setMonth(newDate.getMonth() + 1)
      break
  }
  currentDate.value = newDate
}

function goToToday() {
  currentDate.value = new Date()
}

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

// Drag and drop handlers
function startDrag(e, card, type) {
  e.stopPropagation()
  draggingCard.value = card
  dragType.value = type
  dragStartX.value = e.clientX
  dragStartDate.value = card.start_date ? new Date(card.start_date) : null
  dragEndDate.value = card.due_date ? new Date(card.due_date) : null
  
  document.addEventListener('mousemove', onDrag)
  document.addEventListener('mouseup', endDrag)
}

function onDrag(e) {
  if (!draggingCard.value) return
  
  const deltaX = e.clientX - dragStartX.value
  
  // Update visual preview (we'll update the actual card on drop)
  const cardEl = document.querySelector(`[data-card-id="${draggingCard.value.id}"]`)
  if (!cardEl) return
  
  const originalLeft = draggingCard.value.left
  const originalWidth = draggingCard.value.width
  
  if (dragType.value === 'move') {
    // Move the entire card
    const newLeft = originalLeft + deltaX
    cardEl.style.left = newLeft + 'px'
  } else if (dragType.value === 'resize-start') {
    // Resize from start
    const newLeft = originalLeft + deltaX
    const newWidth = originalWidth - deltaX
    if (newWidth > columnWidth.value) {
      cardEl.style.left = newLeft + 'px'
      cardEl.style.width = newWidth + 'px'
    }
  } else if (dragType.value === 'resize-end') {
    // Resize from end
    const newWidth = originalWidth + deltaX
    if (newWidth > columnWidth.value) {
      cardEl.style.width = newWidth + 'px'
    }
  }
}

async function endDrag(e) {
  document.removeEventListener('mousemove', onDrag)
  document.removeEventListener('mouseup', endDrag)
  
  if (!draggingCard.value) return
  
  const deltaX = e.clientX - dragStartX.value
  const deltaDays = Math.round(deltaX / columnWidth.value)
  
  // Reset DOM styles before Vue re-renders
  const cardEl = document.querySelector(`[data-card-id="${draggingCard.value.id}"]`)
  if (cardEl) {
    cardEl.style.left = ''
    cardEl.style.width = ''
  }
  
  if (deltaDays !== 0) {
    const updates = {}
    const cardId = draggingCard.value.id
    
    if (dragType.value === 'move') {
      // Move both dates
      if (dragStartDate.value) {
        const newStart = new Date(dragStartDate.value)
        newStart.setDate(newStart.getDate() + deltaDays)
        updates.start_date = newStart.toISOString().split('T')[0]
      }
      if (dragEndDate.value) {
        const newEnd = new Date(dragEndDate.value)
        newEnd.setDate(newEnd.getDate() + deltaDays)
        updates.due_date = newEnd.toISOString().split('T')[0]
      }
    } else if (dragType.value === 'resize-start') {
      // Change start date only
      const baseDate = dragStartDate.value || dragEndDate.value
      if (baseDate) {
        const newStart = new Date(baseDate)
        newStart.setDate(newStart.getDate() + deltaDays)
        // Make sure start doesn't go past end date
        if (dragEndDate.value && newStart < dragEndDate.value) {
          updates.start_date = newStart.toISOString().split('T')[0]
        }
      }
    } else if (dragType.value === 'resize-end') {
      // Change end date only
      if (dragEndDate.value) {
        const newEnd = new Date(dragEndDate.value)
        newEnd.setDate(newEnd.getDate() + deltaDays)
        // Make sure end doesn't go before start date
        const startDate = dragStartDate.value || new Date(dragEndDate.value.getTime() - 86400000)
        if (newEnd > startDate) {
          updates.due_date = newEnd.toISOString().split('T')[0]
        }
      }
    }
    
    // Only update if we have valid updates
    if (Object.keys(updates).length > 0) {
      // Optimistic update - update local state immediately
      if (boardsStore.currentBoard) {
        for (const list of boardsStore.currentBoard.lists) {
          const card = list.cards.find(c => c.id === cardId)
          if (card) {
            if (updates.start_date) card.start_date = updates.start_date
            if (updates.due_date) card.due_date = updates.due_date
            break
          }
        }
      }
      
      try {
        await boardsStore.updateCard(cardId, updates)
        toast.success('Card dates updated')
      } catch (err) {
        console.error('Failed to update card dates:', err)
        toast.error('Failed to update dates')
        // Revert on error - refetch the board
        await boardsStore.fetchBoard(boardsStore.currentBoard?.id)
      }
    }
  }
  
  draggingCard.value = null
  dragType.value = null
}

// Find today's position for the indicator line
const todayPosition = computed(() => {
  const today = new Date()
  const rangeStart = dateRange.value.start.getTime()
  const todayOffset = (today.getTime() - rangeStart) / (1000 * 60 * 60 * 24)
  return todayOffset * columnWidth.value
})

// Get position for a date on timeline
function getDatePosition(date) {
  const rangeStart = dateRange.value.start.getTime()
  const dateOffset = (date.getTime() - rangeStart) / (1000 * 60 * 60 * 24)
  return dateOffset * columnWidth.value
}

// Check if date is in visible range
function isDateInRange(date) {
  return date >= dateRange.value.start && date <= dateRange.value.end
}

// Format currency with proper locale (HUF uses space as thousand separator)
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
</script>

<template>
  <div ref="timelineContainer" class="h-full flex flex-col bg-white dark:bg-surface-900">
    <!-- Header -->
    <div class="px-2 md:px-4 py-2 md:py-3 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between gap-2">
      <div class="flex items-center gap-2 md:gap-4 min-w-0">
        <h2 class="text-sm md:text-lg font-semibold text-surface-900 dark:text-surface-100 hidden md:block">
          Timeline
        </h2>
        
        <div class="flex items-center gap-0.5 md:gap-1">
          <button 
            @click="previousPeriod"
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
            @click="nextPeriod"
            class="p-1 md:p-1.5 hover:bg-surface-100 dark:hover:bg-surface-800 rounded-lg transition-colors"
          >
            <span class="material-symbols-rounded text-xl md:text-2xl">chevron_right</span>
          </button>
        </div>
      </div>
      
      <div class="flex items-center gap-2 md:gap-4 flex-shrink-0">
        <!-- Show completed toggle -->
        <div 
          @click="showCompleted = !showCompleted"
          :class="[
            'w-8 h-5 rounded-full relative transition-colors cursor-pointer flex-shrink-0',
            showCompleted ? 'bg-primary-500' : 'bg-surface-300 dark:bg-surface-600'
          ]"
          :title="showCompleted ? 'Hide completed' : 'Show completed'"
        >
          <div 
            :class="[
              'absolute top-0.5 w-4 h-4 rounded-full bg-white transition-transform shadow-sm',
              showCompleted ? 'translate-x-3.5' : 'translate-x-0.5'
            ]"
          ></div>
        </div>
        <span class="text-xs md:text-sm text-surface-600 dark:text-surface-400 hidden md:inline">Show completed</span>
        
        <!-- Zoom controls -->
        <div class="flex items-center gap-0.5 md:gap-1 p-0.5 md:p-1 bg-surface-100 dark:bg-surface-800 rounded-lg">
          <button 
            @click="zoomLevel = 'day'"
            :class="[
              'px-1.5 md:px-3 py-1 text-xs md:text-sm font-medium rounded-md transition-colors',
              zoomLevel === 'day' 
                ? 'bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 shadow-sm' 
                : 'text-surface-600 dark:text-surface-400'
            ]"
          >
            {{ isMobile ? 'D' : 'Day' }}
          </button>
          <button 
            @click="zoomLevel = 'week'"
            :class="[
              'px-1.5 md:px-3 py-1 text-xs md:text-sm font-medium rounded-md transition-colors',
              zoomLevel === 'week' 
                ? 'bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 shadow-sm' 
                : 'text-surface-600 dark:text-surface-400'
            ]"
          >
            {{ isMobile ? 'W' : 'Week' }}
          </button>
          <button 
            @click="zoomLevel = 'month'"
            :class="[
              'px-1.5 md:px-3 py-1 text-xs md:text-sm font-medium rounded-md transition-colors',
              zoomLevel === 'month' 
                ? 'bg-white dark:bg-surface-700 text-surface-900 dark:text-surface-100 shadow-sm' 
                : 'text-surface-600 dark:text-surface-400'
            ]"
          >
            {{ isMobile ? 'M' : 'Month' }}
          </button>
        </div>
      </div>
    </div>
    
    <!-- Timeline -->
    <div class="flex-1 overflow-auto">
      <div class="flex min-h-full">
        <!-- List names sidebar - narrower on mobile -->
        <div :class="['shrink-0 border-r border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800 sticky left-0 z-20', isMobile ? 'w-32' : 'w-48']">
          <!-- Header spacer -->
          <div class="h-10 md:h-12 border-b border-surface-200 dark:border-surface-700"></div>
          
          <!-- Milestones row header -->
          <div 
            v-if="milestones.length > 0"
            :class="['px-2 md:px-4 flex items-center border-b border-surface-100 dark:border-surface-700 bg-amber-50 dark:bg-amber-900/20', isMobile ? 'h-12' : 'h-14']"
          >
            <span class="material-symbols-rounded text-xs md:text-sm text-amber-500 mr-1 md:mr-2">payments</span>
            <span class="text-xs md:text-sm font-medium text-amber-700 dark:text-amber-400 truncate">
              {{ isMobile ? 'Mile...' : 'Milestones' }}
            </span>
            <span class="ml-1 md:ml-2 text-[10px] md:text-xs text-amber-500">{{ milestones.length }}</span>
          </div>
          
          <!-- List rows -->
          <div v-for="(group, listId) in cardsByList" :key="listId">
            <div 
              :class="['px-2 md:px-4 flex items-center border-b border-surface-100 dark:border-surface-700', isMobile ? 'h-10' : 'h-12']"
              v-if="group.cards.length > 0 || true"
            >
              <div 
                class="w-2 md:w-3 h-2 md:h-3 rounded-full mr-1 md:mr-2 flex-shrink-0"
                :style="{ backgroundColor: getListColor(parseInt(listId)) }"
              ></div>
              <span class="text-xs md:text-sm font-medium text-surface-700 dark:text-surface-300 truncate">
                {{ group.list.name }}
              </span>
              <span class="ml-1 md:ml-2 text-[10px] md:text-xs text-surface-500">{{ group.cards.length }}</span>
            </div>
          </div>
        </div>
        
        <!-- Timeline grid -->
        <div class="flex-1 relative min-w-0">
          <!-- Column headers -->
          <div 
            :class="['border-b border-surface-200 dark:border-surface-700 flex sticky top-0 bg-surface-50 dark:bg-surface-800 z-10', isMobile ? 'h-10' : 'h-12']"
            :style="{ minWidth: totalWidth + 'px' }"
          >
            <div
              v-for="(col, i) in timelineColumns"
              :key="i"
              :class="[
                'shrink-0 border-r border-surface-100 dark:border-surface-700 flex items-end justify-center pb-1 md:pb-2',
                col.isToday ? 'bg-primary-500/10' : col.isWeekend ? 'bg-surface-100 dark:bg-surface-800/50' : ''
              ]"
              :style="{ width: columnWidth + 'px' }"
            >
              <span 
                :class="[
                  'text-[10px] md:text-xs',
                  col.isToday ? 'text-primary-500 font-semibold' : 
                  col.isMonthStart ? 'text-surface-900 dark:text-surface-100 font-medium' : 'text-surface-500'
                ]"
              >
                {{ col.label }}
              </span>
            </div>
          </div>
          
          <!-- Grid body -->
          <div 
            class="relative"
            :style="{ minWidth: totalWidth + 'px' }"
          >
            <!-- Today indicator line -->
            <div 
              v-if="todayPosition >= 0 && todayPosition <= totalWidth"
              class="absolute top-0 bottom-0 w-0.5 bg-primary-500 z-10"
              :style="{ left: todayPosition + 'px' }"
            >
              <div class="absolute -top-1 -left-1.5 w-4 h-4 bg-primary-500 rounded-full"></div>
            </div>
            
            <!-- Milestones row -->
            <div 
              v-if="milestones.length > 0"
              :class="['relative border-b border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-900/10', isMobile ? 'h-12' : 'h-14']"
            >
              <!-- Background grid lines -->
              <div class="absolute inset-0 flex">
                <div
                  v-for="(col, i) in timelineColumns"
                  :key="i"
                  :class="[
                    'shrink-0 border-r border-surface-100 dark:border-surface-700/50',
                    col.isWeekend ? 'bg-surface-50/50 dark:bg-surface-800/20' : ''
                  ]"
                  :style="{ width: columnWidth + 'px' }"
                ></div>
              </div>
              
              <!-- Milestone markers with progress -->
              <div
                v-for="milestone in milestones"
                :key="milestone.id"
                v-show="isDateInRange(milestone.invoice_date)"
                class="absolute top-1 flex flex-col items-center cursor-pointer group -translate-x-1/2"
                :style="{ left: (getDatePosition(milestone.invoice_date) + columnWidth / 2) + 'px' }"
              >
                <!-- Milestone diamond marker -->
                <div 
                  class="w-8 h-8 flex items-center justify-center transform rotate-45 shadow-lg transition-transform group-hover:scale-110"
                  :class="milestone.progress_percent >= 100 ? 'bg-green-500' : 'bg-amber-500'"
                  style="border-radius: 4px;"
                >
                  <span 
                    class="material-symbols-rounded text-white text-lg transform -rotate-45"
                    style="font-variation-settings: 'FILL' 1;"
                  >
                    {{ milestone.progress_percent >= 100 ? 'check_circle' : 'payments' }}
                  </span>
                </div>
                
                <!-- Progress indicator bar below -->
                <div class="w-8 h-1 mt-1 bg-surface-300 dark:bg-surface-600 rounded-full overflow-hidden">
                  <div 
                    class="h-full transition-all rounded-full"
                    :class="milestone.progress_percent >= 100 ? 'bg-green-500' : 'bg-amber-500'"
                    :style="{ width: `${milestone.progress_percent}%` }"
                  ></div>
                </div>
                
                <!-- Tooltip on hover -->
                <div class="absolute top-full left-1/2 -translate-x-1/2 mt-3 px-3 py-2 bg-surface-800 text-white text-xs rounded-lg shadow-lg whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity z-30 min-w-[180px]">
                  <div class="font-medium mb-1">{{ milestone.name }}</div>
                  <div class="text-amber-300 font-semibold text-sm">{{ formatCurrency(milestone.expected_amount, milestone.currency) }}</div>
                  
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
            </div>
            
            <!-- List rows with cards -->
            <div v-for="(group, listId) in cardsByList" :key="listId">
              <div 
                :class="['relative border-b border-surface-100 dark:border-surface-700', isMobile ? 'h-10' : 'h-12']"
                v-if="group.cards.length > 0 || true"
              >
                <!-- Background grid lines -->
                <div class="absolute inset-0 flex">
                  <div
                    v-for="(col, i) in timelineColumns"
                    :key="i"
                    :class="[
                      'shrink-0 border-r border-surface-100 dark:border-surface-700/50',
                      col.isWeekend ? 'bg-surface-50 dark:bg-surface-800/30' : ''
                    ]"
                    :style="{ width: columnWidth + 'px' }"
                  ></div>
                </div>
                
                <!-- Cards for this list -->
                <div 
                  v-for="card in group.cards"
                  :key="card.id"
                  :data-card-id="card.id"
                  @click="openCard(card)"
                  :class="[
                    'absolute rounded-lg flex items-center text-[10px] md:text-xs font-medium text-white cursor-pointer transition-shadow hover:shadow-lg overflow-hidden group',
                    isMobile ? 'top-1 h-8' : 'top-2 h-8',
                    getCardStatus(card) === 'complete' ? 'opacity-50' : '',
                    getCardStatus(card) === 'overdue' ? 'ring-2 ring-red-500' : '',
                    draggingCard?.id === card.id ? 'opacity-70 shadow-xl z-20' : ''
                  ]"
                  :style="{ 
                    left: card.left + 'px', 
                    width: Math.max(card.width, 30) + 'px',
                    backgroundColor: card.list_color
                  }"
                  :title="card.title"
                >
                  <!-- Left resize handle -->
                  <div 
                    class="absolute left-0 top-0 bottom-0 w-2 cursor-ew-resize opacity-0 group-hover:opacity-100 hover:bg-white/30 transition-opacity flex items-center justify-center"
                    @mousedown.stop="startDrag($event, card, 'resize-start')"
                    @click.stop
                  >
                    <div class="w-0.5 h-4 bg-white/70 rounded-full"></div>
                  </div>
                  
                  <!-- Main draggable area -->
                  <div 
                    class="flex-1 px-2 flex items-center cursor-grab active:cursor-grabbing overflow-hidden"
                    @mousedown="startDrag($event, card, 'move')"
                  >
                    <span class="truncate">{{ card.title }}</span>
                    <span 
                      v-if="card.completed"
                      class="material-symbols-rounded text-sm ml-1 shrink-0"
                    >
                      check_circle
                    </span>
                  </div>
                  
                  <!-- Right resize handle -->
                  <div 
                    class="absolute right-0 top-0 bottom-0 w-2 cursor-ew-resize opacity-0 group-hover:opacity-100 hover:bg-white/30 transition-opacity flex items-center justify-center"
                    @mousedown.stop="startDrag($event, card, 'resize-end')"
                    @click.stop
                  >
                    <div class="w-0.5 h-4 bg-white/70 rounded-full"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Legend -->
    <div class="px-4 py-2 border-t border-surface-200 dark:border-surface-700 flex items-center gap-6 text-xs text-surface-500">
      <div class="flex items-center gap-2">
        <div class="w-3 h-3 rounded-full bg-primary-500"></div>
        <span>Today</span>
      </div>
      <div class="flex items-center gap-2">
        <div class="w-8 h-3 rounded bg-surface-400 ring-2 ring-red-500"></div>
        <span>Overdue</span>
      </div>
      <div class="flex items-center gap-2">
        <div class="w-8 h-3 rounded bg-surface-400 opacity-50"></div>
        <span>Completed</span>
      </div>
    </div>
  </div>
</template>

