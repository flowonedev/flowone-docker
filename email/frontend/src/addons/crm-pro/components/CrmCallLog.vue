<script setup>
/**
 * CrmCallLog - Unified call log for a client
 * Shows manual phone logs, portal video calls, and guest calls in one timeline.
 */
import { ref, watch, computed } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  clientId: { type: Number, required: true },
})

const toast = useToastStore()
const calls = ref([])
const loading = ref(false)
const showNew = ref(false)
const showAll = ref(false)

const newCall = ref({
  direction: 'outbound',
  duration_minutes: null,
  outcome: 'connected',
  notes: '',
  call_date: new Date().toISOString().slice(0, 16),
})

watch(() => props.clientId, () => fetchCalls(), { immediate: true })

const visibleCalls = computed(() =>
  showAll.value ? calls.value : calls.value.slice(0, 8)
)

async function fetchCalls() {
  loading.value = true
  try {
    const res = await api.get(`/clients/${props.clientId}/call-log`)
    if (res.data?.success) calls.value = res.data.data?.calls || []
  } catch (e) { calls.value = [] }
  loading.value = false
}

async function logCall() {
  try {
    await api.post(`/clients/${props.clientId}/call-log`, newCall.value)
    toast.success('Call logged')
    showNew.value = false
    newCall.value = { direction: 'outbound', duration_minutes: null, outcome: 'connected', notes: '', call_date: new Date().toISOString().slice(0, 16) }
    fetchCalls()
  } catch (e) {
    toast.error('Failed to log call')
  }
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function formatDuration(c) {
  if (c.source === 'portal' && c.duration_minutes) {
    return c.duration_minutes < 1 ? '<1m' : Math.round(c.duration_minutes) + 'm'
  }
  if (c.duration_minutes) return c.duration_minutes + 'm'
  return ''
}

function getCallIcon(c) {
  if (c.source === 'portal') {
    if (c.status === 'cancelled') return 'cancel'
    if (c.status === 'active' || c.status === 'waiting') return 'videocam'
    return 'video_call'
  }
  return outcomeIcons[c.outcome]?.icon || 'call'
}

function getCallColor(c) {
  if (c.source === 'portal') {
    if (c.status === 'cancelled') return 'text-red-400'
    if (c.status === 'active') return 'text-green-500'
    if (c.status === 'waiting') return 'text-amber-500'
    return 'text-indigo-500'
  }
  return outcomeIcons[c.outcome]?.color || 'text-surface-400'
}

function getCallLabel(c) {
  if (c.source === 'portal') {
    const type = c.call_type === 'scheduled' ? 'Scheduled' : 'Quick'
    if (c.status === 'active') return `${type} video call (live)`
    if (c.status === 'waiting') return `${type} video call (waiting)`
    if (c.status === 'cancelled') return `${type} video call (cancelled)`
    return `${type} video call`
  }
  return c.direction === 'inbound' ? 'Inbound call' : 'Outbound call'
}

const outcomeIcons = {
  connected: { icon: 'call', color: 'text-green-500' },
  no_answer: { icon: 'call_missed', color: 'text-red-400' },
  voicemail: { icon: 'voicemail', color: 'text-amber-500' },
  busy: { icon: 'phone_disabled', color: 'text-surface-400' },
  callback_requested: { icon: 'call_received', color: 'text-blue-500' },
}
</script>

<template>
  <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-surface-700 p-4">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100 flex items-center gap-2">
        <span class="material-symbols-rounded text-lg text-primary-500">phone_in_talk</span>
        Call Log
      </h3>
      <button @click="showNew = !showNew"
              class="text-xs text-primary-600 hover:text-primary-700 font-medium flex items-center gap-0.5">
        <span class="material-symbols-rounded text-sm">add</span> Log Call
      </button>
    </div>

    <!-- New Call Form -->
    <div v-if="showNew" class="p-3 mb-3 bg-surface-50 dark:bg-surface-800/50 rounded-lg space-y-2">
      <div class="grid grid-cols-2 gap-2">
        <select v-model="newCall.direction"
                class="px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs">
          <option value="outbound">Outbound</option>
          <option value="inbound">Inbound</option>
        </select>
        <select v-model="newCall.outcome"
                class="px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs">
          <option value="connected">Connected</option>
          <option value="no_answer">No Answer</option>
          <option value="voicemail">Voicemail</option>
          <option value="busy">Busy</option>
          <option value="callback_requested">Callback Requested</option>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <input v-model.number="newCall.duration_minutes" type="number" min="0" placeholder="Duration (min)"
               class="px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs" />
        <input v-model="newCall.call_date" type="datetime-local"
               class="px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs" />
      </div>
      <textarea v-model="newCall.notes" placeholder="Notes" rows="2"
                class="w-full px-3 py-2 rounded-lg border border-surface-300 dark:border-surface-600 bg-white dark:bg-surface-700 text-xs resize-none"></textarea>
      <div class="flex justify-end gap-2">
        <button @click="showNew = false" class="px-3 py-1.5 text-xs text-surface-500">Cancel</button>
        <button @click="logCall"
                class="px-4 py-1.5 rounded-lg bg-primary-600 text-white text-xs font-medium hover:bg-primary-700">
          Log Call
        </button>
      </div>
    </div>

    <!-- Call List -->
    <div v-if="loading" class="text-center py-3">
      <div class="animate-spin w-5 h-5 border-2 border-primary-500 border-t-transparent rounded-full mx-auto"></div>
    </div>
    <div v-else-if="calls.length" class="space-y-1">
      <div v-for="c in visibleCalls" :key="c.id"
           class="flex items-start gap-2 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-surface-800/50 text-xs">
        <span :class="['material-symbols-rounded text-sm mt-0.5', getCallColor(c)]">
          {{ getCallIcon(c) }}
        </span>
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-1.5 flex-wrap">
            <span class="font-medium text-surface-700 dark:text-surface-200"
                  :class="{ 'line-through opacity-50': c.status === 'cancelled' }">
              {{ getCallLabel(c) }}
            </span>
            <span v-if="c.source === 'manual'" class="text-surface-400">{{ c.outcome }}</span>
            <span v-if="formatDuration(c)" class="text-surface-400">{{ formatDuration(c) }}</span>
            <span v-if="c.source === 'portal' && c.has_transcript"
                  class="material-symbols-rounded text-[10px] text-indigo-400" title="Has transcript">description</span>
            <span v-if="c.source === 'portal' && c.had_screen_share"
                  class="material-symbols-rounded text-[10px] text-blue-400" title="Had screen share">screen_share</span>
          </div>
          <p v-if="c.notes" class="text-surface-400 truncate mt-0.5">{{ c.notes }}</p>
          <p v-if="c.source === 'portal' && c.created_by" class="text-surface-400 text-[10px] mt-0.5">
            by {{ c.created_by }}
          </p>
        </div>
        <span class="text-surface-400 text-[10px] whitespace-nowrap shrink-0">{{ formatDate(c.call_date) }}</span>
      </div>
      <button v-if="calls.length > 8 && !showAll" @click="showAll = true"
              class="w-full text-center text-xs text-primary-500 hover:text-primary-600 py-1.5 font-medium">
        Show all {{ calls.length }} calls
      </button>
    </div>
    <p v-else class="text-xs text-surface-400 text-center py-2">No calls logged</p>
  </div>
</template>

