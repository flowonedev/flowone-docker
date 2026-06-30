<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useCalendarStore } from '@/addons/calendar/stores/calendar'
import { useTodosStore } from '@/addons/tasks/stores/todos'
import { useAddons } from '@/composables/useAddons'

const emit = defineEmits(['switch-to-todos'])

const router = useRouter()
const calendarStore = useCalendarStore()
const todosStore = useTodosStore()
const { calendarEnabled } = useAddons()

const calendarCurrentDate = ref(new Date())
const calendarSelectedDate = ref(new Date())

const calendarMonthName = computed(() =>
  calendarCurrentDate.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
)

const calendarDays = computed(() => {
  const year = calendarCurrentDate.value.getFullYear()
  const month = calendarCurrentDate.value.getMonth()
  const firstDay = new Date(year, month, 1)
  const lastDay = new Date(year, month + 1, 0)
  const days = []
  const today = new Date()
  today.setHours(0, 0, 0, 0)

  const startPadding = firstDay.getDay()
  const prevMonth = new Date(year, month, 0)
  for (let i = startPadding - 1; i >= 0; i--) {
    const date = new Date(year, month - 1, prevMonth.getDate() - i)
    days.push({
      date,
      day: date.getDate(),
      isCurrentMonth: false,
      isToday: date.toDateString() === today.toDateString()
    })
  }
  for (let day = 1; day <= lastDay.getDate(); day++) {
    const date = new Date(year, month, day)
    days.push({
      date,
      day,
      isCurrentMonth: true,
      isToday: date.toDateString() === today.toDateString()
    })
  }
  const remaining = 42 - days.length
  for (let day = 1; day <= remaining; day++) {
    const date = new Date(year, month + 1, day)
    days.push({
      date,
      day,
      isCurrentMonth: false,
      isToday: date.toDateString() === today.toDateString()
    })
  }
  return days
})

const selectedDateEvents = computed(() => {
  if (!calendarStore.events) return []
  const dateStr = calendarSelectedDate.value.toDateString()
  return calendarStore.events
    .filter(event => new Date(event.start_time).toDateString() === dateStr)
    .sort((a, b) => new Date(a.start_time) - new Date(b.start_time))
})

const upcomingEvents = computed(() => {
  if (!calendarStore.events) return []
  const now = new Date()
  now.setHours(0, 0, 0, 0)
  return calendarStore.events
    .filter(event => new Date(event.start_time) >= now)
    .sort((a, b) => new Date(a.start_time) - new Date(b.start_time))
    .slice(0, 10)
})

function hasEventsOnDate(date) {
  if (!calendarStore.events) return false
  const dateStr = date.toDateString()
  return calendarStore.events.some(event => new Date(event.start_time).toDateString() === dateStr)
}

function selectCalendarDate(day) {
  calendarSelectedDate.value = day.date
}
function prevCalendarMonth() {
  const d = new Date(calendarCurrentDate.value)
  d.setMonth(d.getMonth() - 1)
  calendarCurrentDate.value = d
}
function nextCalendarMonth() {
  const d = new Date(calendarCurrentDate.value)
  d.setMonth(d.getMonth() + 1)
  calendarCurrentDate.value = d
}

function formatEventTime(event) {
  if (event.all_day) return 'All day'
  return new Date(event.start_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function formatEventDate(event) {
  const date = new Date(event.start_time)
  const today = new Date()
  const tomorrow = new Date()
  tomorrow.setDate(tomorrow.getDate() + 1)
  if (date.toDateString() === today.toDateString()) return 'Today'
  if (date.toDateString() === tomorrow.toDateString()) return 'Tomorrow'
  return date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
}

function getEventColor(event) {
  if (!event.calendar_id || !calendarStore.calendars) return '#3b82f6'
  const cal = calendarStore.calendars.find(c => c.id === event.calendar_id)
  if (cal?.color) {
    const colorObj = calendarStore.calendarColors.find(c => c.id === cal.color)
    return colorObj?.hex || '#3b82f6'
  }
  return '#3b82f6'
}

function goToCalendar() {
  router.push('/calendar')
  todosStore.closePanel()
}

onMounted(() => {
  if (calendarEnabled.value) {
    calendarStore.fetchEvents()
    calendarStore.fetchCalendars()
  }
})

watch(() => todosStore.panelOpen, (isOpen) => {
  if (isOpen && calendarEnabled.value) {
    calendarStore.fetchEvents()
    calendarStore.fetchCalendars()
  }
})
</script>

<template>
  <div class="flex-1 flex flex-col overflow-hidden">
    <div class="p-3 border-b border-surface-200 dark:border-surface-700">
      <div class="flex items-center justify-between mb-3">
        <span class="text-sm font-semibold text-surface-900 dark:text-surface-100">
          {{ calendarMonthName }}
        </span>
        <div class="flex items-center gap-1">
          <button class="p-1 rounded hover:bg-surface-100 dark:hover:bg-surface-700" @click="prevCalendarMonth">
            <span class="material-symbols-rounded text-lg text-surface-500">chevron_left</span>
          </button>
          <button class="p-1 rounded hover:bg-surface-100 dark:hover:bg-surface-700" @click="nextCalendarMonth">
            <span class="material-symbols-rounded text-lg text-surface-500">chevron_right</span>
          </button>
        </div>
      </div>

      <div class="grid grid-cols-7 mb-1">
        <div
          v-for="(day, idx) in ['S', 'M', 'T', 'W', 'T', 'F', 'S']"
          :key="idx"
          class="text-center text-xs font-medium text-surface-400 py-1"
        >
          {{ day }}
        </div>
      </div>

      <div class="grid grid-cols-7 gap-0.5">
        <button
          v-for="(day, index) in calendarDays"
          :key="index"
          :class="[
            'relative w-8 h-8 mx-auto rounded-full text-xs font-medium transition-colors',
            day.isToday
              ? 'bg-primary-500 text-white'
              : day.date.toDateString() === calendarSelectedDate.toDateString()
                ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-600 dark:text-primary-400'
                : day.isCurrentMonth
                  ? 'text-surface-900 dark:text-surface-100 hover:bg-surface-100 dark:hover:bg-surface-700'
                  : 'text-surface-400 dark:text-surface-600 hover:bg-surface-100 dark:hover:bg-surface-700'
          ]"
          @click="selectCalendarDate(day)"
        >
          {{ day.day }}
          <span
            v-if="hasEventsOnDate(day.date) && !day.isToday"
            class="absolute bottom-0.5 left-1/2 -translate-x-1/2 w-1 h-1 rounded-full bg-primary-500"
          ></span>
        </button>
      </div>
    </div>

    <div class="flex-1 overflow-y-auto">
      <div class="p-3 border-b border-surface-200 dark:border-surface-700">
        <div class="flex items-center gap-2 mb-2">
          <span class="text-sm font-semibold text-surface-900 dark:text-surface-100">
            {{ calendarSelectedDate.toDateString() === new Date().toDateString() ? 'Today' : formatEventDate({ start_time: calendarSelectedDate }) }}
          </span>
          <span class="text-xs text-surface-500">
            {{ calendarSelectedDate.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) }}
          </span>
        </div>

        <div v-if="selectedDateEvents.length === 0" class="text-sm text-surface-500 dark:text-surface-400 py-2">
          No events scheduled
          <button class="block text-primary-500 hover:text-primary-600 font-medium mt-1" @click="goToCalendar">
            Create new event
          </button>
        </div>

        <div v-else class="space-y-2">
          <div
            v-for="event in selectedDateEvents"
            :key="event.id"
            class="flex items-start gap-2 py-1.5 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-700/50 -mx-2 px-2 rounded-lg transition-colors"
            @click="goToCalendar"
          >
            <div class="w-1 h-full min-h-[32px] rounded-full flex-shrink-0" :style="{ backgroundColor: getEventColor(event) }"></div>
            <div class="flex-1 min-w-0">
              <div class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ event.title }}</div>
              <div class="text-xs text-surface-500">
                {{ formatEventTime(event) }}
                <span v-if="event.location"> &middot; {{ event.location }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="p-3 border-b border-surface-200 dark:border-surface-700">
        <button
          class="flex items-center gap-2 text-sm text-surface-600 dark:text-surface-400 hover:text-surface-900 dark:hover:text-surface-100"
          @click="emit('switch-to-todos')"
        >
          <span class="material-symbols-rounded text-lg">add</span>
          Add a task due today
        </button>
      </div>

      <div class="p-3">
        <h3 class="text-xs font-semibold text-surface-500 uppercase tracking-wide mb-2">
          Upcoming
        </h3>

        <div v-if="upcomingEvents.length === 0" class="text-sm text-surface-500 dark:text-surface-400 py-2">
          No upcoming events
        </div>

        <div v-else class="space-y-1">
          <div
            v-for="event in upcomingEvents"
            :key="event.id"
            class="flex items-start gap-2 py-2 cursor-pointer hover:bg-surface-50 dark:hover:bg-surface-700/50 -mx-2 px-2 rounded-lg transition-colors"
            @click="goToCalendar"
          >
            <div class="w-1 h-full min-h-[40px] rounded-full flex-shrink-0" :style="{ backgroundColor: getEventColor(event) }"></div>
            <div class="flex-1 min-w-0">
              <div class="text-xs text-surface-500 mb-0.5">{{ formatEventDate(event) }}</div>
              <div class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ event.title }}</div>
              <div class="text-xs text-surface-500">
                {{ formatEventTime(event) }}
                <span v-if="event.location" class="flex items-center gap-0.5 mt-0.5">
                  <span class="material-symbols-rounded text-xs">location_on</span>
                  {{ event.location }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="px-4 py-3 border-t border-surface-200 dark:border-surface-700 bg-white dark:bg-transparent">
      <button
        class="w-full px-4 py-2 text-sm text-primary-500 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded-lg font-medium flex items-center justify-center gap-2"
        @click="goToCalendar"
      >
        <span class="material-symbols-rounded text-lg">add</span>
        New event
      </button>
    </div>
  </div>
</template>
