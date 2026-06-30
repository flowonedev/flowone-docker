<script setup>
import { ref, computed } from 'vue'

const emit = defineEmits(['schedule', 'close'])

const selectedOption = ref('custom')
const customDate = ref('')
const customTime = ref('')

// Quick schedule options
const quickOptions = computed(() => {
  const now = new Date()
  
  // Later today (2 hours from now or 6pm, whichever is later)
  const laterToday = new Date(now)
  laterToday.setHours(Math.max(now.getHours() + 2, 18), 0, 0, 0)
  
  // Tomorrow morning 9am
  const tomorrowMorning = new Date(now)
  tomorrowMorning.setDate(tomorrowMorning.getDate() + 1)
  tomorrowMorning.setHours(9, 0, 0, 0)
  
  // Next Monday 9am
  const nextMonday = new Date(now)
  const dayOfWeek = nextMonday.getDay()
  const daysUntilMonday = dayOfWeek === 0 ? 1 : 8 - dayOfWeek
  nextMonday.setDate(nextMonday.getDate() + daysUntilMonday)
  nextMonday.setHours(9, 0, 0, 0)
  
  return [
    { label: 'Later today', sublabel: formatDateTime(laterToday), value: laterToday.toISOString(), icon: 'schedule' },
    { label: 'Tomorrow morning', sublabel: formatDateTime(tomorrowMorning), value: tomorrowMorning.toISOString(), icon: 'wb_sunny' },
    { label: 'Next Monday', sublabel: formatDateTime(nextMonday), value: nextMonday.toISOString(), icon: 'next_week' },
  ]
})

function formatDateTime(date) {
  return date.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' }) + 
    ' at ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function selectQuick(option) {
  emit('schedule', option.value)
}

function submitCustom() {
  if (!customDate.value || !customTime.value) return
  const dt = new Date(`${customDate.value}T${customTime.value}`)
  if (dt <= new Date()) return
  emit('schedule', dt.toISOString())
}

// Set min date to today
const minDate = computed(() => {
  return new Date().toISOString().split('T')[0]
})
</script>

<template>
  <div class="absolute bottom-full right-0 mb-2 w-72 bg-white dark:bg-surface-800 rounded-xl shadow-xl border border-surface-200 dark:border-surface-700 z-50 overflow-hidden">
    <!-- Header -->
    <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
      <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Schedule Message</h3>
      <button @click="$emit('close')" class="p-1 hover:bg-surface-100 dark:hover:bg-surface-700 rounded-full transition-colors">
        <span class="material-symbols-rounded text-lg text-surface-400">close</span>
      </button>
    </div>
    
    <!-- Quick options -->
    <div class="py-1">
      <button
        v-for="option in quickOptions"
        :key="option.label"
        @click="selectQuick(option)"
        class="w-full flex items-center gap-3 px-4 py-2.5 text-left hover:bg-surface-50 dark:hover:bg-surface-700 transition-colors"
      >
        <span class="material-symbols-rounded text-xl text-surface-400">{{ option.icon }}</span>
        <div class="flex-1 min-w-0">
          <span class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ option.label }}</span>
          <p class="text-xs text-surface-500">{{ option.sublabel }}</p>
        </div>
      </button>
    </div>
    
    <!-- Custom date/time -->
    <div class="px-4 py-3 border-t border-surface-200 dark:border-surface-700">
      <p class="text-xs font-medium text-surface-500 mb-2 uppercase tracking-wide">Custom</p>
      <div class="flex gap-2">
        <input
          v-model="customDate"
          type="date"
          :min="minDate"
          class="flex-1 px-2 py-1.5 text-sm bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg text-surface-900 dark:text-surface-100"
        />
        <input
          v-model="customTime"
          type="time"
          class="w-24 px-2 py-1.5 text-sm bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 rounded-lg text-surface-900 dark:text-surface-100"
        />
      </div>
      <button
        @click="submitCustom"
        :disabled="!customDate || !customTime"
        class="w-full mt-2 px-3 py-1.5 text-sm font-medium bg-primary-500 text-white rounded-full hover:bg-primary-600 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
      >
        Schedule
      </button>
    </div>
  </div>
</template>

