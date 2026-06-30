<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="$emit('close')">
    <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-[500px] max-h-[80vh] flex flex-col overflow-hidden" @click.stop>
      <!-- Header -->
      <div class="flex items-center justify-between px-5 py-3 border-b border-surface-200 dark:border-surface-700">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-primary-500">calendar_month</span>
          Add Calendar Event
        </h3>
        <button @click="$emit('close')" class="p-1 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-400">
          <span class="material-symbols-rounded text-lg">close</span>
        </button>
      </div>

      <!-- Search -->
      <div class="px-5 py-2 border-b border-surface-100 dark:border-surface-700/50">
        <div class="relative">
          <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-lg text-surface-400">search</span>
          <input
            v-model="searchQuery"
            placeholder="Search events..."
            class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-surface-200 dark:border-surface-600 bg-surface-50 dark:bg-surface-700 text-surface-900 dark:text-surface-100 focus:ring-2 focus:ring-primary-500"
          />
        </div>
      </div>

      <!-- Content -->
      <div class="flex-1 overflow-y-auto p-3 min-h-[300px]">
        <!-- Loading -->
        <div v-if="loadingEvents" class="flex items-center justify-center h-full py-10">
          <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">sync</span>
        </div>

        <!-- Empty -->
        <div v-else-if="filteredEvents.length === 0" class="flex flex-col items-center justify-center py-10 text-surface-400">
          <span class="material-symbols-rounded text-4xl">event_busy</span>
          <p class="text-sm mt-2">No events found</p>
        </div>

        <!-- Event list -->
        <div v-else class="space-y-1">
          <!-- Group by date -->
          <template v-for="(group, dateKey) in groupedEvents" :key="dateKey">
            <div class="text-xs font-semibold text-surface-400 uppercase tracking-wide px-3 pt-3 pb-1">
              {{ dateKey }}
            </div>
            <button
              v-for="event in group"
              :key="event.id"
              @click="toggleSelection(event)"
              :class="[
                'w-full flex items-start gap-3 px-3 py-2.5 rounded-xl text-left transition-colors',
                isSelected(event)
                  ? 'bg-primary-50 dark:bg-primary-900/20 ring-1 ring-primary-300 dark:ring-primary-700'
                  : 'hover:bg-surface-50 dark:hover:bg-surface-700/50'
              ]"
            >
              <div
                class="w-3 h-3 rounded-full mt-1 flex-shrink-0"
                :style="{ backgroundColor: event.color || '#3b82f6' }"
              />
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ event.title || 'Untitled Event' }}</p>
                <p class="text-xs text-surface-500 mt-0.5">
                  {{ formatEventTime(event) }}
                </p>
                <p v-if="event.location" class="text-xs text-surface-400 mt-0.5 flex items-center gap-1">
                  <span class="material-symbols-rounded text-xs">location_on</span>
                  {{ event.location }}
                </p>
              </div>
              <div v-if="isSelected(event)" class="w-5 h-5 rounded-full bg-primary-500 flex items-center justify-center flex-shrink-0 mt-0.5">
                <span class="material-symbols-rounded text-sm text-white">check</span>
              </div>
            </button>
          </template>
        </div>
      </div>

      <!-- Footer -->
      <div class="flex items-center justify-between px-5 py-3 border-t border-surface-200 dark:border-surface-700">
        <span class="text-xs text-surface-400">{{ selectedEvents.length }} event(s) selected</span>
        <div class="flex items-center gap-2">
          <button @click="$emit('close')" class="px-4 py-2 text-sm rounded-full hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-600 dark:text-surface-400 transition-colors">
            Cancel
          </button>
          <button
            @click="confirmSelection"
            :disabled="selectedEvents.length === 0"
            :class="[
              'px-5 py-2 text-sm font-medium rounded-full transition-colors',
              selectedEvents.length > 0
                ? 'bg-primary-500 hover:bg-primary-600 text-white'
                : 'bg-surface-200 dark:bg-surface-700 text-surface-400 cursor-not-allowed'
            ]"
          >
            Add {{ selectedEvents.length > 0 ? `(${selectedEvents.length})` : '' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'

const emit = defineEmits(['select', 'close'])

const loadingEvents = ref(false)
const events = ref([])
const selectedEvents = ref([])
const searchQuery = ref('')

// Filtered events
const filteredEvents = computed(() => {
  if (!searchQuery.value) return events.value
  const q = searchQuery.value.toLowerCase()
  return events.value.filter(e =>
    (e.title || '').toLowerCase().includes(q) ||
    (e.location || '').toLowerCase().includes(q) ||
    (e.description || '').toLowerCase().includes(q)
  )
})

// Group by date
const groupedEvents = computed(() => {
  const groups = {}
  for (const event of filteredEvents.value) {
    const date = new Date(event.start || event.dtstart)
    const dateKey = date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
    if (!groups[dateKey]) groups[dateKey] = []
    groups[dateKey].push(event)
  }
  return groups
})

// ========================================
// DATA LOADING
// ========================================

async function fetchEvents() {
  loadingEvents.value = true
  try {
    // Fetch events for the next 3 months and past 1 month
    const now = new Date()
    const startDate = new Date(now.getFullYear(), now.getMonth() - 1, 1).toISOString()
    const endDate = new Date(now.getFullYear(), now.getMonth() + 3, 0).toISOString()

    const response = await api.get('/events', {
      params: { start: startDate, end: endDate }
    })
    if (response.data.success) {
      events.value = (response.data.data.events || []).sort((a, b) => {
        const dateA = new Date(a.start || a.dtstart)
        const dateB = new Date(b.start || b.dtstart)
        return dateA - dateB
      })
    }
  } catch (e) {
    console.error('Failed to fetch calendar events:', e)
  } finally {
    loadingEvents.value = false
  }
}

// ========================================
// SELECTION
// ========================================

function toggleSelection(event) {
  const idx = selectedEvents.value.findIndex(e => e.id === event.id)
  if (idx >= 0) {
    selectedEvents.value.splice(idx, 1)
  } else {
    selectedEvents.value.push(event)
  }
}

function isSelected(event) {
  return selectedEvents.value.some(e => e.id === event.id)
}

function confirmSelection() {
  emit('select', selectedEvents.value)
}

// ========================================
// HELPERS
// ========================================

function formatEventTime(event) {
  const start = new Date(event.start || event.dtstart)
  const end = event.end || event.dtend ? new Date(event.end || event.dtend) : null

  if (event.all_day || event.allDay) {
    return 'All day'
  }

  const timeStr = start.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
  if (end) {
    const endStr = end.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
    return `${timeStr} - ${endStr}`
  }
  return timeStr
}

onMounted(() => {
  fetchEvents()
})
</script>

