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
const sortBy = ref('list')
const sortDir = ref('asc')
const filterList = ref('')
const filterLabel = ref('')
const filterDue = ref('')

// Computed
const lists = computed(() => boardsStore.currentLists || [])
const labels = computed(() => boardsStore.currentLabels || [])

const allCards = computed(() => {
  return lists.value.flatMap(list => 
    list.cards
      .filter(card => !card.parent_card_id)
      .map(card => ({
      ...card,
      list_name: list.name,
      list_id: list.id
    }))
  )
})

const filteredCards = computed(() => {
  let cards = [...allCards.value]
  
  // Filter by list
  if (filterList.value) {
    cards = cards.filter(c => c.list_id === parseInt(filterList.value))
  }
  
  // Filter by label
  if (filterLabel.value) {
    cards = cards.filter(c => c.labels?.some(l => l.id === parseInt(filterLabel.value)))
  }
  
  // Filter by due date
  if (filterDue.value) {
    const now = new Date()
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
    const tomorrow = new Date(today)
    tomorrow.setDate(tomorrow.getDate() + 1)
    const nextWeek = new Date(today)
    nextWeek.setDate(nextWeek.getDate() + 7)
    
    cards = cards.filter(c => {
      if (!c.due_date) return filterDue.value === 'none'
      const due = new Date(c.due_date)
      
      switch (filterDue.value) {
        case 'overdue': return due < today && !c.completed
        case 'today': return due >= today && due < tomorrow
        case 'week': return due >= today && due < nextWeek
        case 'none': return false
        default: return true
      }
    })
  }
  
  // Sort
  cards.sort((a, b) => {
    let aVal, bVal
    
    switch (sortBy.value) {
      case 'title':
        aVal = a.title.toLowerCase()
        bVal = b.title.toLowerCase()
        break
      case 'list':
        aVal = lists.value.findIndex(l => l.id === a.list_id)
        bVal = lists.value.findIndex(l => l.id === b.list_id)
        break
      case 'due':
        aVal = a.due_date ? new Date(a.due_date).getTime() : Infinity
        bVal = b.due_date ? new Date(b.due_date).getTime() : Infinity
        break
      case 'created':
        aVal = new Date(a.created_at).getTime()
        bVal = new Date(b.created_at).getTime()
        break
      default:
        return 0
    }
    
    if (sortDir.value === 'asc') {
      return aVal < bVal ? -1 : aVal > bVal ? 1 : 0
    } else {
      return aVal > bVal ? -1 : aVal < bVal ? 1 : 0
    }
  })
  
  return cards
})

// Methods
function toggleSort(column) {
  if (sortBy.value === column) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortBy.value = column
    sortDir.value = 'asc'
  }
}

function openCard(card) {
  emit('open-card', card)
}

function formatDate(dateStr) {
  if (!dateStr) return '-'
  const date = new Date(dateStr)
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function getDueStatus(card) {
  if (!card.due_date) return null
  if (card.completed) return 'complete'
  
  const now = new Date()
  const due = new Date(card.due_date)
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const tomorrow = new Date(today)
  tomorrow.setDate(tomorrow.getDate() + 1)
  
  if (due < today) return 'overdue'
  if (due < tomorrow) return 'today'
  return 'upcoming'
}

function clearFilters() {
  filterList.value = ''
  filterLabel.value = ''
  filterDue.value = ''
}
</script>

<template>
  <div class="h-full flex flex-col bg-white dark:bg-surface-900">
    <!-- Filters - scrollable on mobile -->
    <div class="px-3 md:px-4 py-2 md:py-3 border-b border-surface-200 dark:border-surface-700">
      <div class="flex items-center gap-3 overflow-x-auto pb-1 md:pb-0">
        <span class="text-sm text-surface-500 flex-shrink-0">Filter:</span>
        
        <!-- List filter -->
        <select 
          v-model="filterList"
          class="px-2 md:px-3 py-1.5 bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 rounded-lg text-sm text-surface-700 dark:text-surface-300 outline-none focus:border-primary-500 flex-shrink-0"
        >
          <option value="">All lists</option>
          <option v-for="list in lists" :key="list.id" :value="list.id">{{ list.name }}</option>
        </select>
        
        <!-- Label filter -->
        <select 
          v-model="filterLabel"
          class="px-2 md:px-3 py-1.5 bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 rounded-lg text-sm text-surface-700 dark:text-surface-300 outline-none focus:border-primary-500 flex-shrink-0"
        >
          <option value="">All labels</option>
          <option v-for="label in labels" :key="label.id" :value="label.id">{{ label.name || 'Unnamed' }}</option>
        </select>
        
        <!-- Due date filter -->
        <select 
          v-model="filterDue"
          class="px-2 md:px-3 py-1.5 bg-surface-50 dark:bg-surface-800 border border-surface-200 dark:border-surface-600 rounded-lg text-sm text-surface-700 dark:text-surface-300 outline-none focus:border-primary-500 flex-shrink-0"
        >
          <option value="">Any due</option>
          <option value="overdue">Overdue</option>
          <option value="today">Today</option>
          <option value="week">This week</option>
          <option value="none">No date</option>
        </select>
        
        <button 
          v-if="filterList || filterLabel || filterDue"
          @click="clearFilters"
          class="px-2 py-1 text-sm text-surface-500 hover:text-surface-700 dark:hover:text-surface-300 flex-shrink-0"
        >
          Clear
        </button>
        
        <div class="ml-auto text-sm text-surface-500 flex-shrink-0">
          {{ filteredCards.length }} cards
        </div>
      </div>
    </div>
    
    <!-- Mobile Card View -->
    <div v-if="isMobile" class="flex-1 overflow-auto p-3 space-y-2">
      <!-- Sort buttons for mobile -->
      <div class="flex items-center gap-2 mb-3 overflow-x-auto pb-1">
        <span class="text-xs text-surface-500 flex-shrink-0">Sort:</span>
        <button 
          v-for="sort in [
            { id: 'list', label: 'List' },
            { id: 'title', label: 'Title' },
            { id: 'due', label: 'Due' }
          ]"
          :key="sort.id"
          @click="toggleSort(sort.id)"
          :class="[
            'px-2 py-1 text-xs rounded-full flex-shrink-0 transition-colors',
            sortBy === sort.id 
              ? 'bg-primary-500 text-white' 
              : 'bg-surface-100 dark:bg-surface-800 text-surface-600 dark:text-surface-400'
          ]"
        >
          {{ sort.label }}
          <span v-if="sortBy === sort.id" class="ml-0.5">{{ sortDir === 'asc' ? '↑' : '↓' }}</span>
        </button>
      </div>
      
      <!-- Cards list -->
      <div 
        v-for="card in filteredCards"
        :key="card.id"
        @click="openCard(card)"
        class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 p-3 active:bg-surface-50 dark:active:bg-surface-700 transition-colors"
        :class="{ 'opacity-50': card.completed }"
      >
        <div class="flex items-start gap-3">
          <!-- Checkbox -->
          <div 
            :class="[
              'w-5 h-5 rounded border-2 flex items-center justify-center flex-shrink-0 mt-0.5',
              card.completed 
                ? 'bg-primary-500 border-primary-500 text-white' 
                : 'border-surface-300 dark:border-surface-600'
            ]"
          >
            <span v-if="card.completed" class="material-symbols-rounded text-sm">check</span>
          </div>
          
          <div class="flex-1 min-w-0">
            <!-- Title -->
            <p 
              class="text-sm font-medium text-surface-900 dark:text-surface-100"
              :class="{ 'line-through': card.completed }"
            >
              {{ card.title }}
            </p>
            
            <!-- List name -->
            <p class="text-xs text-surface-500 mt-0.5">{{ card.list_name }}</p>
            
            <!-- Bottom row: labels, due date, progress -->
            <div class="flex items-center gap-3 mt-2 flex-wrap">
              <!-- Labels as dots -->
              <div v-if="card.labels?.length" class="flex items-center gap-1">
                <span
                  v-for="label in (card.labels || []).slice(0, 4)"
                  :key="label.id"
                  class="w-2.5 h-2.5 rounded-full"
                  :style="{ backgroundColor: label.color }"
                ></span>
                <span v-if="card.labels?.length > 4" class="text-[10px] text-surface-400">+{{ card.labels.length - 4 }}</span>
              </div>
              
              <!-- Due date -->
              <span 
                v-if="card.due_date"
                :class="[
                  'inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium',
                  getDueStatus(card) === 'overdue' ? 'bg-red-500/20 text-red-600 dark:text-red-400' :
                  getDueStatus(card) === 'today' ? 'bg-amber-500/20 text-amber-600 dark:text-amber-400' :
                  getDueStatus(card) === 'complete' ? 'bg-green-500/20 text-green-600 dark:text-green-400' :
                  'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400'
                ]"
              >
                <span class="material-symbols-rounded text-xs">schedule</span>
                {{ formatDate(card.due_date) }}
              </span>
              
              <!-- Progress -->
              <div v-if="card.checklist_total > 0" class="flex items-center gap-1.5">
                <div class="w-10 h-1 bg-surface-200 dark:bg-surface-600 rounded-full overflow-hidden">
                  <div 
                    class="h-full bg-primary-500"
                    :style="{ width: (card.checklist_done / card.checklist_total * 100) + '%' }"
                  ></div>
                </div>
                <span class="text-[10px] text-surface-500">{{ card.checklist_done }}/{{ card.checklist_total }}</span>
              </div>
              
              <!-- Indicators -->
              <div class="flex items-center gap-1 ml-auto">
                <span v-if="card.description" class="material-symbols-rounded text-xs text-surface-400">subject</span>
                <span v-if="card.attachment_count > 0" class="material-symbols-rounded text-xs text-surface-400">attach_file</span>
                <span v-if="card.comment_count > 0" class="material-symbols-rounded text-xs text-surface-400">chat_bubble</span>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Empty state -->
      <div v-if="filteredCards.length === 0" class="text-center py-12">
        <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">table_rows</span>
        <p class="text-surface-500 mt-2">No cards found</p>
      </div>
    </div>
    
    <!-- Desktop Table View -->
    <div v-else class="flex-1 overflow-auto">
      <table class="w-full">
        <thead class="sticky top-0 bg-surface-50 dark:bg-surface-800 z-10">
          <tr>
            <th class="w-10 px-4 py-3 text-left">
              <span class="sr-only">Complete</span>
            </th>
            <th 
              @click="toggleSort('title')"
              class="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wide cursor-pointer hover:text-surface-700 dark:hover:text-surface-300"
            >
              <div class="flex items-center gap-1">
                Title
                <span v-if="sortBy === 'title'" class="material-symbols-rounded text-sm">
                  {{ sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
                </span>
              </div>
            </th>
            <th 
              @click="toggleSort('list')"
              class="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wide cursor-pointer hover:text-surface-700 dark:hover:text-surface-300 w-40"
            >
              <div class="flex items-center gap-1">
                List
                <span v-if="sortBy === 'list'" class="material-symbols-rounded text-sm">
                  {{ sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
                </span>
              </div>
            </th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wide w-32">
              Labels
            </th>
            <th 
              @click="toggleSort('due')"
              class="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wide cursor-pointer hover:text-surface-700 dark:hover:text-surface-300 w-32"
            >
              <div class="flex items-center gap-1">
                Due
                <span v-if="sortBy === 'due'" class="material-symbols-rounded text-sm">
                  {{ sortDir === 'asc' ? 'arrow_upward' : 'arrow_downward' }}
                </span>
              </div>
            </th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-surface-500 uppercase tracking-wide w-24">
              Progress
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-surface-100 dark:divide-surface-700">
          <tr 
            v-for="card in filteredCards"
            :key="card.id"
            @click="openCard(card)"
            class="hover:bg-surface-50 dark:hover:bg-surface-800 cursor-pointer transition-colors"
            :class="{ 'opacity-50': card.completed }"
          >
            <td class="px-4 py-3">
              <div 
                :class="[
                  'w-5 h-5 rounded border-2 flex items-center justify-center',
                  card.completed 
                    ? 'bg-primary-500 border-primary-500 text-white' 
                    : 'border-surface-300 dark:border-surface-600'
                ]"
              >
                <span v-if="card.completed" class="material-symbols-rounded text-sm">check</span>
              </div>
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <span 
                  class="text-sm text-surface-900 dark:text-surface-100"
                  :class="{ 'line-through': card.completed }"
                >
                  {{ card.title }}
                </span>
                <span v-if="card.description" class="material-symbols-rounded text-sm text-surface-400">subject</span>
                <span v-if="card.attachment_count > 0" class="material-symbols-rounded text-sm text-surface-400">attach_file</span>
                <span v-if="card.comment_count > 0" class="material-symbols-rounded text-sm text-surface-400">chat_bubble</span>
              </div>
            </td>
            <td class="px-4 py-3">
              <span class="text-sm text-surface-600 dark:text-surface-400">
                {{ card.list_name }}
              </span>
            </td>
            <td class="px-4 py-3">
              <div class="flex flex-wrap gap-1">
                <span
                  v-for="label in (card.labels || []).slice(0, 3)"
                  :key="label.id"
                  class="w-6 h-2 rounded-full"
                  :style="{ backgroundColor: label.color }"
                  :title="label.name"
                ></span>
                <span 
                  v-if="card.labels?.length > 3"
                  class="text-xs text-surface-500"
                >
                  +{{ card.labels.length - 3 }}
                </span>
              </div>
            </td>
            <td class="px-4 py-3">
              <span 
                v-if="card.due_date"
                :class="[
                  'inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium',
                  getDueStatus(card) === 'overdue' ? 'bg-red-500/20 text-red-600 dark:text-red-400' :
                  getDueStatus(card) === 'today' ? 'bg-amber-500/20 text-amber-600 dark:text-amber-400' :
                  getDueStatus(card) === 'complete' ? 'bg-green-500/20 text-green-600 dark:text-green-400' :
                  'bg-surface-100 dark:bg-surface-700 text-surface-600 dark:text-surface-400'
                ]"
              >
                {{ formatDate(card.due_date) }}
              </span>
              <span v-else class="text-surface-400">-</span>
            </td>
            <td class="px-4 py-3">
              <div v-if="card.checklist_total > 0" class="flex items-center gap-2">
                <div class="w-16 h-1.5 bg-surface-200 dark:bg-surface-600 rounded-full overflow-hidden">
                  <div 
                    class="h-full bg-primary-500"
                    :style="{ width: (card.checklist_done / card.checklist_total * 100) + '%' }"
                  ></div>
                </div>
                <span class="text-xs text-surface-500">
                  {{ card.checklist_done }}/{{ card.checklist_total }}
                </span>
              </div>
              <span v-else class="text-surface-400">-</span>
            </td>
          </tr>
        </tbody>
      </table>
      
      <!-- Empty state -->
      <div v-if="filteredCards.length === 0" class="text-center py-12">
        <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">table_rows</span>
        <p class="text-surface-500 mt-2">No cards found</p>
      </div>
    </div>
  </div>
</template>

