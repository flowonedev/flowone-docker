<script setup>
/**
 * CrmRemindersSection - Follow-up reminders for a client
 * Shows pending/completed reminders, allows creating and completing.
 */
import { ref, watch, computed } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  clientId: { type: Number, required: true },
})

const toast = useToastStore()
const reminders = ref([])
const loading = ref(false)
const showNew = ref(false)
const showCompleted = ref(false)

const newReminder = ref({
  title: '',
  description: '',
  remind_at: '',
  is_recurring: false,
  recurrence_interval: 'weekly',
})

const pending = computed(() => reminders.value.filter(r => !r.is_completed))
const completed = computed(() => reminders.value.filter(r => r.is_completed))

watch(() => props.clientId, () => fetchReminders(), { immediate: true })

async function fetchReminders() {
  loading.value = true
  try {
    const res = await api.get('/crm/reminders', { params: { client_id: props.clientId } })
    if (res.data?.success) reminders.value = res.data.data?.reminders || []
  } catch (e) { reminders.value = [] }
  loading.value = false
}

async function createReminder() {
  if (!newReminder.value.title.trim() || !newReminder.value.remind_at) {
    toast.error('Title and date are required')
    return
  }
  try {
    await api.post('/crm/reminders', {
      client_id: props.clientId,
      ...newReminder.value,
      is_recurring: newReminder.value.is_recurring ? 1 : 0,
      recurrence_interval: newReminder.value.is_recurring ? newReminder.value.recurrence_interval : null,
    })
    toast.success('Reminder created')
    showNew.value = false
    newReminder.value = { title: '', description: '', remind_at: '', is_recurring: false, recurrence_interval: 'weekly' }
    fetchReminders()
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to create reminder')
  }
}

async function completeReminder(r) {
  try {
    await api.post(`/crm/reminders/${r.id}/complete`)
    toast.success('Reminder completed')
    fetchReminders()
  } catch (e) {
    toast.error('Failed to complete')
  }
}

async function deleteReminder(r) {
  try {
    await api.delete(`/crm/reminders/${r.id}`)
    fetchReminders()
  } catch (e) {
    toast.error('Failed to delete')
  }
}

function isOverdue(r) {
  return !r.is_completed && new Date(r.remind_at) < new Date()
}

function formatDate(d) {
  if (!d) return ''
  const dt = new Date(d)
  const now = new Date()
  const diff = dt - now
  const days = Math.ceil(diff / 86400000)

  if (days === 0) return 'Today'
  if (days === 1) return 'Tomorrow'
  if (days === -1) return 'Yesterday'
  if (days < -1) return `${Math.abs(days)} days ago`
  if (days <= 7) return `In ${days} days`
  return dt.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
</script>

<template>
  <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg text-primary-500">notifications_active</span>
        Reminders
        <span v-if="pending.length" class="text-xs font-normal bg-amber-100 dark:bg-amber-500/20 text-amber-700 dark:text-amber-300 px-1.5 py-0.5 rounded-full">{{ pending.length }}</span>
      </h3>
      <button @click="showNew = !showNew"
              class="text-xs text-primary-600 hover:text-primary-700 font-medium flex items-center gap-0.5">
        <span class="material-symbols-rounded text-sm">add</span> Add
      </button>
    </div>

    <!-- New Reminder Form -->
    <div v-if="showNew" class="p-3 mb-3 bg-surface-50 dark:bg-surface-800/50 rounded-lg space-y-2">
      <input v-model="newReminder.title" placeholder="Reminder title"
             class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
      <input v-model="newReminder.remind_at" type="datetime-local"
             class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none" />
      <textarea v-model="newReminder.description" placeholder="Notes (optional)" rows="2"
                class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-sm focus:ring-2 focus:ring-primary-500 outline-none resize-none"></textarea>
      <label class="flex items-center gap-2 text-xs text-surface-600 dark:text-surface-300">
        <input type="checkbox" v-model="newReminder.is_recurring" class="w-3.5 h-3.5 rounded border-surface-300 text-primary-600 focus:ring-primary-500" />
        Recurring
        <select v-if="newReminder.is_recurring" v-model="newReminder.recurrence_interval"
                class="ml-1 px-2 py-1 rounded border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs">
          <option value="daily">Daily</option>
          <option value="weekly">Weekly</option>
          <option value="biweekly">Biweekly</option>
          <option value="monthly">Monthly</option>
        </select>
      </label>
      <div class="flex justify-end gap-2">
        <button @click="showNew = false" class="px-3 py-1.5 text-xs text-surface-500">Cancel</button>
        <button @click="createReminder"
                class="px-4 py-1.5 rounded-lg bg-primary-600 text-white text-xs font-medium hover:bg-primary-700">
          Create
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-3">
      <div class="animate-spin w-5 h-5 border-2 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
    </div>

    <!-- Pending -->
    <div v-else-if="pending.length" class="space-y-1.5">
      <div v-for="r in pending" :key="r.id"
           class="flex items-start gap-2 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800/50 group">
        <button @click="completeReminder(r)"
                class="mt-0.5 w-5 h-5 rounded-full border-2 flex-shrink-0 transition-colors"
                :class="isOverdue(r) ? 'border-red-400 hover:bg-red-100' : 'border-surface-300 hover:bg-green-100 dark:hover:bg-green-500/20'">
        </button>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-surface-800 dark:text-white truncate">{{ r.title }}</p>
          <p class="text-xs" :class="isOverdue(r) ? 'text-red-500 font-medium' : 'text-surface-400'">
            {{ formatDate(r.remind_at) }}
            <span v-if="r.is_recurring" class="ml-1">🔄 {{ r.recurrence_interval }}</span>
          </p>
        </div>
        <button @click="deleteReminder(r)"
                class="p-1 rounded text-surface-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity">
          <span class="material-symbols-rounded text-sm">close</span>
        </button>
      </div>
    </div>
    <p v-else class="text-xs text-surface-400 text-center py-2">No pending reminders</p>

    <!-- Completed toggle -->
    <div v-if="completed.length" class="mt-2">
      <button @click="showCompleted = !showCompleted" class="text-xs text-surface-400 hover:text-surface-600">
        {{ showCompleted ? 'Hide' : 'Show' }} {{ completed.length }} completed
      </button>
      <div v-if="showCompleted" class="mt-1 space-y-1 opacity-60">
        <div v-for="r in completed" :key="r.id" class="flex items-center gap-2 p-1.5 text-xs">
          <span class="material-symbols-rounded text-sm text-green-500">check_circle</span>
          <span class="line-through text-surface-400">{{ r.title }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

