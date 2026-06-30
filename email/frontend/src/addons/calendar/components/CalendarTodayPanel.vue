<script setup>
import { computed, onMounted, onUnmounted } from 'vue'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'

const calendar = useCalendarStore()

// Start the now ticker when this panel mounts
onMounted(() => {
  calendar.startNowTicker()
})

onUnmounted(() => {
  calendar.stopNowTicker()
})

// Today's formatted date
const todayLabel = computed(() => {
  const d = new Date()
  return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })
})

// Mini calendar data for current month
const miniCalDays = computed(() => {
  const today = new Date()
  const year = today.getFullYear()
  const month = today.getMonth()
  const firstDay = new Date(year, month, 1)
  const lastDay = new Date(year, month + 1, 0)
  // Monday-start offset: Mon=0, Tue=1, ..., Sun=6
  const rawDay = firstDay.getDay()
  const startOffset = rawDay === 0 ? 6 : rawDay - 1
  const daysInMonth = lastDay.getDate()

  const days = []
  // Previous month padding
  const prevMonth = new Date(year, month, 0)
  for (let i = startOffset - 1; i >= 0; i--) {
    days.push({ day: prevMonth.getDate() - i, isCurrentMonth: false, isToday: false })
  }
  // Current month
  for (let i = 1; i <= daysInMonth; i++) {
    const d = new Date(year, month, i)
    days.push({
      day: i,
      isCurrentMonth: true,
      isToday: d.toDateString() === today.toDateString(),
      hasEvents: calendar.getEventsForDay(d).length > 0,
    })
  }
  // Next month padding
  const remaining = 42 - days.length
  for (let i = 1; i <= remaining; i++) {
    days.push({ day: i, isCurrentMonth: false, isToday: false })
  }
  return days
})

const monthLabel = computed(() => {
  const d = new Date()
  return d.toLocaleDateString('en-US', { month: 'long' })
})

// Format event time
function formatTime(dateStr) {
  const d = new Date(dateStr)
  return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })
}

// Format event duration
function formatDuration(startStr, endStr) {
  const start = new Date(startStr)
  const end = new Date(endStr)
  const diffMin = Math.round((end - start) / 60000)
  if (diffMin < 60) return `${diffMin} min`
  const hrs = Math.floor(diffMin / 60)
  const mins = diffMin % 60
  if (mins === 0) return hrs === 1 ? '1 hour' : `${hrs} hours`
  return `${hrs} hr ${mins} min`
}

// Get event color
function getEventColor(event) {
  if (event.color) return event.color
  if (event.calendar_color) return event.calendar_color
  const cal = calendar.calendars.find(c => c.id === event.calendar_id)
  if (cal) return calendar.getCalendarColor(cal)
  return '#3b82f6'
}

// Get countdown badge style based on status
function getCountdownClass(status) {
  switch (status) {
    case 'now':
    case 'imminent':
      return 'bg-red-500 text-white'
    case 'ongoing':
      return 'bg-green-500 text-white'
    case 'upcoming':
      return 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-400'
    case 'ended':
      return 'bg-surface-200 text-surface-500 dark:bg-surface-700 dark:text-surface-400'
    default:
      return 'bg-surface-100 text-surface-600'
  }
}

const emit = defineEmits(['event-click', 'collapse'])
</script>

<template>
  <div class="h-full flex flex-col bg-white dark:bg-surface-800 border-l border-surface-200 dark:border-surface-700 overflow-hidden">
    <!-- Header -->
    <div class="px-4 pt-4 pb-3 border-b border-surface-200 dark:border-surface-700">
      <div class="flex items-center gap-2">
        <span class="material-symbols-rounded text-primary-500 text-xl">today</span>
        <h3 class="font-semibold text-surface-900 dark:text-surface-100">Today</h3>
        <span class="text-sm text-surface-500 ml-auto">{{ todayLabel }}</span>
        <button
          @click="emit('collapse')"
          class="p-1 -mr-1 rounded-lg text-surface-400 hover:text-surface-600 dark:hover:text-surface-200 hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors"
          title="Hide panel"
        >
          <span class="material-symbols-rounded text-lg">chevron_right</span>
        </button>
      </div>
    </div>
    
    <!-- Mini calendar -->
    <div class="px-3 py-3 border-b border-surface-200 dark:border-surface-700">
      <div class="text-center mb-2">
        <span class="text-xs font-semibold text-surface-600 dark:text-surface-400 tracking-wide">{{ monthLabel }}</span>
      </div>
      <div class="grid grid-cols-7 gap-0">
        <!-- Day headers -->
        <div v-for="d in ['M', 'T', 'W', 'T', 'F', 'S', 'S']" :key="d" class="text-center text-[10px] font-medium text-surface-400 pb-1">{{ d }}</div>
        <!-- Days -->
        <div 
          v-for="(day, i) in miniCalDays" 
          :key="i" 
          class="text-center py-0.5"
        >
          <span
            :class="[
              'inline-flex items-center justify-center w-6 h-6 text-[11px] rounded-full relative',
              day.isToday 
                ? 'bg-primary-500 text-white font-bold' 
                : day.isCurrentMonth 
                  ? 'text-surface-700 dark:text-surface-300' 
                  : 'text-surface-300 dark:text-surface-600'
            ]"
          >
            {{ day.day }}
            <span 
              v-if="day.hasEvents && !day.isToday" 
              class="absolute -bottom-0.5 left-1/2 -translate-x-1/2 w-1 h-1 rounded-full bg-primary-500"
            ></span>
          </span>
        </div>
      </div>
    </div>
    
    <!-- Today's events -->
    <div class="flex-1 overflow-y-auto">
      <!-- Empty state -->
      <div v-if="calendar.todayEvents.length === 0" class="px-4 py-8 text-center">
        <span class="material-symbols-rounded text-3xl text-surface-300 dark:text-surface-600 mb-2 block">event_available</span>
        <p class="text-sm text-surface-500">No events today</p>
      </div>
      
      <!-- Events list -->
      <div v-else class="p-2 space-y-1.5">
        <div 
          v-for="event in calendar.todayEvents" 
          :key="event.virtual_id || event.id"
          @click="emit('event-click', event)"
          class="group relative rounded-xl p-3 cursor-pointer transition-all hover:bg-surface-50 dark:hover:bg-surface-750 border border-transparent hover:border-surface-200 dark:hover:border-surface-700"
        >
          <!-- Countdown badge (for events that haven't ended) -->
          <div v-if="calendar.getEventCountdown(event).status !== 'ended'" class="mb-2">
            <span 
              :class="[
                'inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold',
                getCountdownClass(calendar.getEventCountdown(event).status)
              ]"
            >
              <span class="material-symbols-rounded text-sm">
                {{ calendar.getEventCountdown(event).status === 'ongoing' ? 'play_circle' : 'schedule' }}
              </span>
              {{ calendar.getEventCountdown(event).text }}
            </span>
          </div>
          
          <!-- Event info -->
          <div class="flex items-start gap-2.5">
            <!-- Color bar -->
            <div 
              class="w-1 rounded-full mt-0.5 flex-shrink-0" 
              :style="{ backgroundColor: getEventColor(event), height: '36px' }"
            ></div>
            
            <div class="flex-1 min-w-0">
              <!-- Title -->
              <p 
                class="text-sm font-medium truncate"
                :class="calendar.getEventCountdown(event).status === 'ended' 
                  ? 'text-surface-400 line-through' 
                  : 'text-surface-900 dark:text-surface-100'"
              >
                {{ event.title }}
              </p>
              
              <!-- Time + duration -->
              <div class="flex items-center gap-1.5 mt-0.5">
                <span class="text-xs text-surface-500">
                  {{ formatTime(event.start_time) }}
                </span>
                <span class="text-surface-300 text-xs">-</span>
                <span class="text-xs text-surface-500">
                  {{ formatTime(event.end_time) }}
                </span>
                <span class="text-surface-300 mx-0.5">|</span>
                <span class="text-xs text-surface-400">
                  {{ formatDuration(event.start_time, event.end_time) }}
                </span>
              </div>
              
              <!-- Location -->
              <div v-if="event.location" class="flex items-center gap-1 mt-1">
                <span class="material-symbols-rounded text-xs text-surface-400">location_on</span>
                <span class="text-xs text-surface-400 truncate">{{ event.location }}</span>
              </div>
              
              <!-- Recurring indicator -->
              <div v-if="event.recurrence || event.is_recurrence_instance" class="flex items-center gap-1 mt-1">
                <span class="material-symbols-rounded text-xs text-surface-400">repeat</span>
                <span class="text-xs text-surface-400">Recurring</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Footer - add event shortcut -->
    <div class="px-3 py-2 border-t border-surface-200 dark:border-surface-700">
      <button 
        @click="emit('event-click', null)"
        class="w-full flex items-center justify-center gap-1.5 py-2 text-sm text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded-lg transition-colors font-medium"
      >
        <span class="material-symbols-rounded text-lg">add</span>
        Add event
      </button>
    </div>
  </div>
</template>

