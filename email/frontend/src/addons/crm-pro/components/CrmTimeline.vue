<script setup>
/**
 * CrmTimeline - Unified activity timeline for a client
 * Aggregates portal updates, documents, calls, invoices, deals,
 * call logs, meeting notes, and reminders into a single chronological feed.
 */
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/services/api'

const props = defineProps({
  clientId: {
    type: Number,
    required: true,
  },
})

const events = ref([])
const total = ref(0)
const loading = ref(true)
const loadingMore = ref(false)
const error = ref('')
const limit = 20
const offset = ref(0)
const filterType = ref('all')

const eventTypes = [
  { key: 'all', label: 'All', icon: 'timeline' },
  { key: 'portal_update', label: 'Updates', icon: 'campaign' },
  { key: 'portal_document', label: 'Documents', icon: 'description' },
  { key: 'portal_call', label: 'Portal Calls', icon: 'videocam' },
  { key: 'crm_invoice', label: 'Invoices', icon: 'receipt_long' },
  { key: 'crm_deal', label: 'Deals', icon: 'handshake' },
  { key: 'crm_call_log', label: 'Phone Calls', icon: 'call' },
  { key: 'crm_meeting_note', label: 'Meetings', icon: 'groups' },
  { key: 'crm_reminder', label: 'Reminders', icon: 'notification_important' },
]

const filteredEvents = computed(() => {
  if (filterType.value === 'all') return events.value
  return events.value.filter(e => e.event_type === filterType.value)
})

const hasMore = computed(() => events.value.length < total.value)

onMounted(() => fetchTimeline())

watch(() => props.clientId, () => {
  events.value = []
  offset.value = 0
  fetchTimeline()
})

async function fetchTimeline() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get(`/clients/${props.clientId}/timeline`, {
      params: { limit, offset: 0 },
    })
    if (res.data?.success) {
      events.value = res.data.data.events || []
      total.value = res.data.data.total || 0
      offset.value = events.value.length
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'Failed to load timeline'
  } finally {
    loading.value = false
  }
}

async function loadMore() {
  loadingMore.value = true
  try {
    const res = await api.get(`/clients/${props.clientId}/timeline`, {
      params: { limit, offset: offset.value },
    })
    if (res.data?.success) {
      const newEvents = res.data.data.events || []
      events.value.push(...newEvents)
      total.value = res.data.data.total || 0
      offset.value = events.value.length
    }
  } catch (e) {
    // silent
  } finally {
    loadingMore.value = false
  }
}

function getEventIcon(type) {
  const map = {
    portal_update: 'campaign',
    portal_document: 'description',
    portal_call: 'videocam',
    crm_invoice: 'receipt_long',
    crm_deal: 'handshake',
    crm_call_log: 'call',
    crm_meeting_note: 'groups',
    crm_reminder: 'notification_important',
  }
  return map[type] || 'event'
}

function getEventColor(type) {
  const map = {
    portal_update: 'bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400',
    portal_document: 'bg-purple-100 dark:bg-purple-500/20 text-purple-600 dark:text-purple-400',
    portal_call: 'bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400',
    crm_invoice: 'bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400',
    crm_deal: 'bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400',
    crm_call_log: 'bg-teal-100 dark:bg-teal-500/20 text-teal-600 dark:text-teal-400',
    crm_meeting_note: 'bg-pink-100 dark:bg-pink-500/20 text-pink-600 dark:text-pink-400',
    crm_reminder: 'bg-orange-100 dark:bg-orange-500/20 text-orange-600 dark:text-orange-400',
  }
  return map[type] || 'bg-surface-100 dark:bg-surface-700 text-surface-500'
}

function getEventLabel(type) {
  const map = {
    portal_update: 'Update',
    portal_document: 'Document',
    portal_call: 'Portal Call',
    crm_invoice: 'Invoice',
    crm_deal: 'Deal',
    crm_call_log: 'Phone Call',
    crm_meeting_note: 'Meeting',
    crm_reminder: 'Reminder',
  }
  return map[type] || type
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const diff = now - d
  const mins = Math.floor(diff / 60000)
  const hours = Math.floor(diff / 3600000)
  const days = Math.floor(diff / 86400000)

  if (mins < 1) return 'Just now'
  if (mins < 60) return `${mins}m ago`
  if (hours < 24) return `${hours}h ago`
  if (days < 7) return `${days}d ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: d.getFullYear() !== now.getFullYear() ? 'numeric' : undefined })
}

function formatFullDate(dateStr) {
  if (!dateStr) return ''
  return new Date(dateStr).toLocaleString(undefined, {
    weekday: 'short', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}
</script>

<template>
  <div class="bg-white dark:bg-surface-800 shadow-sm rounded-xl p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
      <h3 class="text-lg font-semibold text-surface-900 dark:text-white flex items-center gap-2">
        <span class="material-symbols-rounded text-xl">timeline</span>
        Activity Timeline
      </h3>
      <span class="text-xs text-surface-400">{{ total }} events</span>
    </div>

    <!-- Type Filters -->
    <div class="flex flex-wrap gap-1.5 mb-4">
      <button
        v-for="t in eventTypes" :key="t.key"
        @click="filterType = t.key"
        :class="[
          'px-2.5 py-1 rounded-lg text-xs font-medium transition-colors flex items-center gap-1',
          filterType === t.key
            ? 'bg-primary-100 dark:bg-primary-500/20 text-primary-700 dark:text-primary-300'
            : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
        ]"
      >
        <span class="material-symbols-rounded text-sm">{{ t.icon }}</span>
        {{ t.label }}
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-8">
      <div class="animate-spin w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full"></div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="text-center py-8">
      <span class="material-symbols-rounded text-3xl text-red-400">error</span>
      <p class="text-sm text-surface-500 mt-2">{{ error }}</p>
      <button @click="fetchTimeline" class="mt-2 text-sm text-primary-600 hover:text-primary-700 font-medium">Retry</button>
    </div>

    <!-- Empty -->
    <div v-else-if="filteredEvents.length === 0" class="text-center py-8">
      <span class="material-symbols-rounded text-4xl text-surface-300 dark:text-surface-600">history</span>
      <p class="text-sm text-surface-500 mt-2">
        {{ filterType === 'all' ? 'No activity recorded yet.' : `No ${getEventLabel(filterType).toLowerCase()} events.` }}
      </p>
    </div>

    <!-- Timeline -->
    <div v-else class="relative">
      <!-- Timeline line -->
      <div class="absolute left-[19px] top-0 bottom-0 w-px bg-surface-200 dark:bg-surface-700"></div>

      <div class="space-y-0">
        <div
          v-for="(event, index) in filteredEvents"
          :key="`${event.event_type}-${event.id}-${index}`"
          class="relative flex gap-4 group"
        >
          <!-- Icon dot -->
          <div class="relative z-10 flex-shrink-0 mt-1">
            <div :class="['w-10 h-10 rounded-full flex items-center justify-center', getEventColor(event.event_type)]">
              <span class="material-symbols-rounded text-lg">{{ getEventIcon(event.event_type) }}</span>
            </div>
          </div>

          <!-- Content -->
          <div class="flex-1 pb-6 min-w-0">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-[10px] uppercase tracking-wider font-bold px-1.5 py-0.5 rounded"
                        :class="getEventColor(event.event_type)">
                    {{ getEventLabel(event.event_type) }}
                  </span>
                  <span class="text-xs text-surface-400" :title="formatFullDate(event.event_date)">
                    {{ formatDate(event.event_date) }}
                  </span>
                </div>
                <h4 class="font-medium text-surface-900 dark:text-white text-sm mt-1 truncate">
                  {{ event.event_title }}
                </h4>
                <p v-if="event.event_detail" class="text-xs text-surface-500 mt-0.5 truncate">
                  {{ event.event_detail }}
                </p>
                <p v-if="event.event_actor" class="text-[10px] text-surface-400 mt-0.5">
                  by {{ event.event_actor }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Load More -->
      <div v-if="hasMore && filterType === 'all'" class="text-center pt-2">
        <button
          @click="loadMore"
          :disabled="loadingMore"
          class="px-4 py-2 rounded-lg text-sm font-medium text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-500/10 transition-colors disabled:opacity-50"
        >
          <span v-if="loadingMore" class="animate-spin material-symbols-rounded text-base align-middle mr-1">progress_activity</span>
          {{ loadingMore ? 'Loading...' : 'Load More' }}
        </button>
      </div>
    </div>
  </div>
</template>

