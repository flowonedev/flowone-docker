<script setup>
/**
 * CrmDealActivity - Deal-scoped activity feed
 * Shows all timeline events related to a specific deal, including stage transitions.
 */
import { ref, onMounted, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const props = defineProps({
  dealId: { type: [Number, String], required: true },
})

const emit = defineEmits(['close'])
const toast = useToastStore()
const loading = ref(true)
const data = ref(null)

onMounted(() => fetchActivity())

watch(() => props.dealId, () => fetchActivity())

async function fetchActivity() {
  if (!props.dealId) return
  loading.value = true
  try {
    const res = await api.get(`/crm/deals/${props.dealId}/activity`)
    if (res.data?.success) data.value = res.data.data
  } catch (e) {
    toast.error('Failed to load deal activity')
  } finally {
    loading.value = false
  }
}

function formatDate(dateStr) {
  if (!dateStr) return '--'
  const d = new Date(dateStr)
  const now = new Date()
  const diff = now - d
  const hours = Math.floor(diff / 3600000)
  const days = Math.floor(diff / 86400000)

  if (hours < 1) return 'Just now'
  if (hours < 24) return `${hours}h ago`
  if (days < 7) return `${days}d ago`
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

const typeIcons = {
  stage_change: 'swap_horiz',
  deal: 'handshake',
  invoice: 'receipt_long',
  reminder: 'alarm',
  document: 'description',
  update: 'campaign',
  note: 'sticky_note_2',
  call: 'phone',
  meeting: 'groups',
}

const typeColors = {
  stage_change: 'bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-400',
  deal: 'bg-blue-100 dark:bg-blue-500/20 text-blue-600 dark:text-blue-400',
  invoice: 'bg-amber-100 dark:bg-amber-500/20 text-amber-600 dark:text-amber-400',
  reminder: 'bg-orange-100 dark:bg-orange-500/20 text-orange-600 dark:text-orange-400',
  document: 'bg-green-100 dark:bg-green-500/20 text-green-600 dark:text-green-400',
  update: 'bg-purple-100 dark:bg-purple-500/20 text-purple-600 dark:text-purple-400',
  note: 'bg-yellow-100 dark:bg-yellow-500/20 text-yellow-600 dark:text-yellow-400',
  call: 'bg-emerald-100 dark:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400',
  meeting: 'bg-cyan-100 dark:bg-cyan-500/20 text-cyan-600 dark:text-cyan-400',
}
</script>

<template>
  <div class="h-full flex flex-col">
    <!-- Header -->
    <div class="flex items-center justify-between p-4 border-b border-surface-200 dark:border-surface-700">
      <div>
        <h3 class="text-base font-semibold text-surface-900 dark:text-white">Deal Activity</h3>
        <p v-if="data?.deal" class="text-xs text-surface-400 mt-0.5">{{ data.deal.title }}</p>
      </div>
      <button @click="emit('close')" class="w-8 h-8 rounded-full flex items-center justify-center hover:bg-surface-100 dark:hover:bg-surface-700 transition-colors">
        <span class="material-symbols-rounded text-lg text-surface-400">close</span>
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex-1 flex items-center justify-center">
      <div class="animate-spin w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full"></div>
    </div>

    <!-- Activity list -->
    <div v-else-if="data?.activities?.length" class="flex-1 overflow-auto p-4">
      <!-- Stage history summary -->
      <div v-if="data.stage_history?.length" class="mb-4 p-3 rounded-lg bg-indigo-50 dark:bg-indigo-500/10 border border-indigo-200 dark:border-indigo-500/20">
        <p class="text-xs font-medium text-indigo-700 dark:text-indigo-300 mb-2">Stage History</p>
        <div class="flex flex-wrap gap-1.5">
          <span
            v-for="sh in data.stage_history" :key="sh.id"
            class="text-[10px] px-2 py-0.5 rounded-full bg-white dark:bg-surface-800 text-surface-600 dark:text-surface-300 border border-indigo-200 dark:border-indigo-500/30"
          >
            {{ sh.from_stage || 'new' }} → {{ sh.to_stage }}
          </span>
        </div>
      </div>

      <!-- Timeline -->
      <div class="space-y-3">
        <div
          v-for="(activity, i) in data.activities" :key="i"
          class="flex gap-3"
        >
          <!-- Icon -->
          <div :class="['w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0', typeColors[activity.type] || 'bg-surface-100 dark:bg-surface-700 text-surface-500']">
            <span class="material-symbols-rounded text-base">{{ typeIcons[activity.type] || 'event' }}</span>
          </div>
          <!-- Content -->
          <div class="flex-1 min-w-0 pb-3" :class="{ 'border-b border-surface-100 dark:border-surface-800': i < data.activities.length - 1 }">
            <p class="text-sm font-medium text-surface-900 dark:text-white">{{ activity.title }}</p>
            <p v-if="activity.detail" class="text-xs text-surface-400 mt-0.5">{{ activity.detail }}</p>
            <p class="text-[10px] text-surface-400 mt-1">{{ formatDate(activity.date || activity.created_at) }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty -->
    <div v-else class="flex-1 flex items-center justify-center text-surface-400">
      <div class="text-center">
        <span class="material-symbols-rounded text-4xl">history</span>
        <p class="text-sm mt-2">No activity recorded for this deal yet</p>
      </div>
    </div>
  </div>
</template>

