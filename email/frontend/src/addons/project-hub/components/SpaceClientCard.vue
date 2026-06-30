<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import ClientStatusBadge from '@/components/clients/ClientStatusBadge.vue'

const props = defineProps({
  client: { type: Object, required: true },
})

const router = useRouter()

const statusIcon = computed(() => {
  switch (props.client.responsibility) {
    case 'Overdue tasks require attention': return 'warning'
    case 'Waiting on client response': return 'hourglass_top'
    case 'Waiting on internal work': return 'engineering'
    case 'No recent activity': return 'schedule'
    default: return 'check_circle'
  }
})

const statusColor = computed(() => {
  switch (props.client.responsibility) {
    case 'Overdue tasks require attention': return 'text-red-500'
    case 'Waiting on client response': return 'text-amber-500'
    case 'Waiting on internal work': return 'text-blue-500'
    case 'No recent activity': return 'text-surface-400'
    default: return 'text-green-500'
  }
})

function fmtDate(dateStr) {
  if (!dateStr) return ''
  return new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function fmtRate(rate) {
  if (!rate) return null
  return `${Number(rate).toLocaleString()}/hr`
}

function openClient() {
  router.push({ path: '/clients', query: { client_id: props.client.id } })
}
</script>

<template>
  <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden mb-6">
    <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex items-center gap-2">
      <span class="material-symbols-rounded text-primary-500 text-lg">person</span>
      <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Client</h3>
      <button
        @click="openClient"
        class="ml-auto text-xs text-primary-500 hover:text-primary-600 dark:hover:text-primary-400 font-medium flex items-center gap-1 transition-colors"
      >
        CRM
        <span class="material-symbols-rounded text-sm">open_in_new</span>
      </button>
    </div>

    <div class="p-4 space-y-3">
      <!-- Identity row -->
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-primary-100 dark:bg-primary-500/20 flex items-center justify-center flex-shrink-0">
          <span class="text-sm font-bold text-primary-600 dark:text-primary-400">
            {{ (client.display_name || client.domain || '?').charAt(0).toUpperCase() }}
          </span>
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-sm font-semibold text-surface-900 dark:text-surface-100 truncate">
            {{ client.display_name || client.domain }}
          </div>
          <div class="flex items-center gap-2 mt-0.5">
            <ClientStatusBadge :status="client.status || 'active'" :lastActivityAt="client.last_activity_at" :showTime="true" />
            <span v-if="client.primary_contact?.email" class="text-xs text-surface-400 truncate">
              {{ client.primary_contact.email }}
            </span>
          </div>
        </div>
      </div>

      <!-- Responsibility banner -->
      <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-surface-50 dark:bg-surface-700/50">
        <span class="material-symbols-rounded text-lg" :class="statusColor">{{ statusIcon }}</span>
        <span class="text-xs font-medium text-surface-600 dark:text-surface-300">{{ client.responsibility }}</span>
      </div>

      <!-- Quick stats row -->
      <div class="grid grid-cols-3 gap-2">
        <div class="text-center px-2 py-1.5 rounded-lg bg-surface-50 dark:bg-surface-700/40">
          <div class="text-base font-bold text-surface-900 dark:text-surface-100">{{ client.open_task_count }}</div>
          <div class="text-[10px] text-surface-400 leading-tight">Open</div>
        </div>
        <div class="text-center px-2 py-1.5 rounded-lg" :class="client.overdue_task_count > 0 ? 'bg-red-50 dark:bg-red-500/10' : 'bg-surface-50 dark:bg-surface-700/40'">
          <div class="text-base font-bold" :class="client.overdue_task_count > 0 ? 'text-red-600 dark:text-red-400' : 'text-surface-900 dark:text-surface-100'">
            {{ client.overdue_task_count }}
          </div>
          <div class="text-[10px] leading-tight" :class="client.overdue_task_count > 0 ? 'text-red-500/70' : 'text-surface-400'">Overdue</div>
        </div>
        <div class="text-center px-2 py-1.5 rounded-lg bg-surface-50 dark:bg-surface-700/40">
          <div class="text-xs font-semibold text-surface-900 dark:text-surface-100">
            {{ client.next_deadline ? fmtDate(client.next_deadline) : '--' }}
          </div>
          <div class="text-[10px] text-surface-400 leading-tight">Deadline</div>
        </div>
      </div>

      <!-- Contact details (conditional) -->
      <div v-if="client.phone || fmtRate(client.hourly_rate)" class="flex items-center gap-4 text-xs text-surface-500">
        <span v-if="client.phone" class="flex items-center gap-1">
          <span class="material-symbols-rounded text-sm">phone</span>
          {{ client.phone }}
        </span>
        <span v-if="fmtRate(client.hourly_rate)" class="flex items-center gap-1">
          <span class="material-symbols-rounded text-sm">payments</span>
          {{ fmtRate(client.hourly_rate) }}
        </span>
      </div>

      <!-- Notes (truncated) -->
      <div v-if="client.notes" class="text-xs text-surface-400 line-clamp-2 italic">
        {{ client.notes }}
      </div>
    </div>
  </div>
</template>
