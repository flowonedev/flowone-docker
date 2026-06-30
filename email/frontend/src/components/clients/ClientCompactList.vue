<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useClientsStore } from '@/stores/clients'

const props = defineProps({
  selectedId: {
    type: Number,
    default: null
  }
})

const emit = defineEmits(['select'])

const clientsStore = useClientsStore()

// State
const searchQuery = ref('')
const sortColumn = ref('name') // name, status
const sortDirection = ref('asc')

// Computed
const clients = computed(() => clientsStore.filteredClients)

const filteredClients = computed(() => {
  let result = clients.value
  
  // Search filter
  if (searchQuery.value.trim()) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(client => {
      const name = (client.display_name || '').toLowerCase()
      const domain = (client.domain || '').toLowerCase()
      return name.includes(query) || domain.includes(query)
    })
  }
  
  // Sort
  result = [...result].sort((a, b) => {
    let aVal, bVal
    
    switch (sortColumn.value) {
      case 'name':
        aVal = (a.display_name || a.domain || '').toLowerCase()
        bVal = (b.display_name || b.domain || '').toLowerCase()
        break
      case 'status':
        const statusOrder = { attention: 0, waiting: 1, active: 2 }
        aVal = statusOrder[a.status] ?? 3
        bVal = statusOrder[b.status] ?? 3
        break
      default:
        aVal = 0
        bVal = 0
    }
    
    if (sortDirection.value === 'asc') {
      return aVal < bVal ? -1 : aVal > bVal ? 1 : 0
    } else {
      return aVal > bVal ? -1 : aVal < bVal ? 1 : 0
    }
  })
  
  return result
})

// Sort by column
function sortBy(column) {
  if (sortColumn.value === column) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortColumn.value = column
    sortDirection.value = column === 'name' ? 'asc' : 'desc'
  }
}

// Get sort icon
function getSortIcon(column) {
  if (sortColumn.value !== column) return 'unfold_more'
  return sortDirection.value === 'asc' ? 'expand_less' : 'expand_more'
}

// Get status badge class
function getStatusClass(status) {
  switch (status) {
    case 'attention':
      return 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-400'
    case 'waiting':
      return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400'
    case 'active':
      return 'bg-green-100 text-green-700 dark:bg-green-500/20 dark:text-green-400'
    default:
      return 'bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-400'
  }
}

// Handle row click
function selectClient(client) {
  emit('select', client)
}

// Keyboard navigation
function handleKeyDown(event, client, index) {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    selectClient(client)
  } else if (event.key === 'ArrowDown' && index < filteredClients.value.length - 1) {
    event.preventDefault()
    const nextRow = event.target.nextElementSibling
    nextRow?.focus()
  } else if (event.key === 'ArrowUp' && index > 0) {
    event.preventDefault()
    const prevRow = event.target.previousElementSibling
    prevRow?.focus()
  }
}
</script>

<template>
  <div class="client-compact-list h-full flex flex-col">
    <!-- Search Bar -->
    <div class="px-3 py-3 border-b border-surface-200 dark:border-surface-700 flex-shrink-0">
      <div class="relative">
        <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-surface-400 text-lg">
          search
        </span>
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Search clients..."
          class="w-full pl-9 pr-3 py-2 text-sm border border-surface-300 dark:border-surface-600 rounded-lg bg-white dark:bg-surface-800 text-surface-900 dark:text-surface-100 placeholder-surface-400 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
        />
        <button
          v-if="searchQuery"
          @click="searchQuery = ''"
          class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-surface-400 hover:text-surface-600"
        >
          <span class="material-symbols-rounded text-sm">close</span>
        </button>
      </div>
      
      <p class="mt-2 text-xs text-surface-500">
        {{ filteredClients.length }} {{ filteredClients.length === 1 ? 'client' : 'clients' }}
        <span v-if="searchQuery">matching "{{ searchQuery }}"</span>
      </p>
    </div>
    
    <!-- Column Headers -->
    <div class="flex items-center px-3 py-1.5 border-b border-surface-200 dark:border-surface-700 bg-surface-50 dark:bg-surface-800 flex-shrink-0">
      <button 
        @click="sortBy('name')"
        class="flex-1 flex items-center gap-0.5 text-xs font-medium text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-200 transition-colors"
      >
        Client
        <span class="material-symbols-rounded text-sm">{{ getSortIcon('name') }}</span>
      </button>
      <button 
        @click="sortBy('status')"
        class="flex items-center gap-0.5 text-xs font-medium text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-200 transition-colors"
      >
        Status
        <span class="material-symbols-rounded text-sm">{{ getSortIcon('status') }}</span>
      </button>
    </div>
    
    <!-- Client List -->
    <div class="flex-1 overflow-y-auto min-h-0">
      <div
        v-for="(client, index) in filteredClients"
        :key="client.id"
        @click="selectClient(client)"
        @keydown="handleKeyDown($event, client, index)"
        tabindex="0"
        :class="[
          'flex items-center gap-2.5 px-3 py-2.5 cursor-pointer transition-colors border-b border-surface-100 dark:border-surface-700/50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary-500',
          selectedId === client.id
            ? 'bg-primary-50 dark:bg-primary-500/10'
            : 'hover:bg-surface-50 dark:hover:bg-surface-800'
        ]"
      >
        <!-- Avatar -->
        <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
          <span class="material-symbols-rounded text-primary-600 dark:text-primary-400 text-sm">
            {{ client.is_company ? 'apartment' : 'person' }}
          </span>
        </div>
        
        <!-- Name + Email -->
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate leading-tight">
            {{ client.display_name || client.domain }}
          </p>
          <p class="text-xs text-surface-500 truncate leading-tight mt-0.5">
            {{ client.domain || client.email }}
          </p>
        </div>
        
        <!-- Status Badge -->
        <span 
          :class="[getStatusClass(client.status), 'px-2 py-0.5 text-[11px] font-medium rounded-full capitalize whitespace-nowrap flex-shrink-0']"
        >
          {{ client.status }}
        </span>
      </div>
      
      <!-- Empty State -->
      <div v-if="filteredClients.length === 0" class="flex flex-col items-center justify-center py-12 text-center px-4">
        <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">
          {{ searchQuery ? 'search_off' : 'groups' }}
        </span>
        <p class="mt-2 text-sm text-surface-500">
          {{ searchQuery ? 'No clients match your search' : 'No clients yet' }}
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.client-compact-list {
  @apply bg-white dark:bg-[rgb(var(--color-surface))];
}
</style>
