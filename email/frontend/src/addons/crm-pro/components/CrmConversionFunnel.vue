<script setup>
/**
 * CrmConversionFunnel - Visual sales funnel with stage-to-stage conversion rates
 * Shows drop-off percentages, avg days per stage, and velocity metrics.
 */
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()
const loading = ref(true)
const data = ref(null)

const stageIcons = {
  lead: 'person_add', contacted: 'call', proposal: 'description',
  negotiation: 'handshake', won: 'emoji_events',
}

const stageColors = {
  lead: 'text-blue-500', contacted: 'text-indigo-500', proposal: 'text-purple-500',
  negotiation: 'text-amber-500', won: 'text-green-500',
}

const stageBgs = {
  lead: 'bg-blue-50 dark:bg-blue-500/10', contacted: 'bg-indigo-50 dark:bg-indigo-500/10',
  proposal: 'bg-purple-50 dark:bg-purple-500/10', negotiation: 'bg-amber-50 dark:bg-amber-500/10',
  won: 'bg-green-50 dark:bg-green-500/10',
}

const stageLabels = {
  lead: 'Lead', contacted: 'Contacted', proposal: 'Proposal',
  negotiation: 'Negotiation', won: 'Won',
}

onMounted(async () => {
  loading.value = true
  try {
    const res = await api.get('/crm/reports/funnel')
    if (res.data?.success) data.value = res.data.data
  } catch (e) {
    toast.error('Failed to load funnel data')
  } finally {
    loading.value = false
  }
})

const maxFunnelCount = computed(() => {
  if (!data.value?.funnel?.length) return 1
  return Math.max(...data.value.funnel.map(f => f.count), 1)
})
</script>

<template>
  <div>
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin w-6 h-6 border-2 border-primary-500 border-t-transparent rounded-full"></div>
    </div>

    <template v-else-if="data">
      <!-- Top metrics -->
      <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="text-center p-3 rounded-lg bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))] border border-surface-200 dark:border-[rgb(var(--color-border))]">
          <p class="text-2xl font-bold text-primary-600">{{ data.win_rate ?? 0 }}%</p>
          <p class="text-xs text-surface-500">Win Rate</p>
        </div>
        <div class="text-center p-3 rounded-lg bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))] border border-surface-200 dark:border-[rgb(var(--color-border))]">
          <p class="text-2xl font-bold text-surface-900 dark:text-white">{{ data.avg_days_to_close ?? '--' }}</p>
          <p class="text-xs text-surface-500">Avg Days to Close</p>
        </div>
        <div class="text-center p-3 rounded-lg bg-surface-50 dark:bg-[rgb(var(--color-surface-elevated))] border border-surface-200 dark:border-[rgb(var(--color-border))]">
          <p class="text-2xl font-bold text-red-500">{{ data.lost_count ?? 0 }}</p>
          <p class="text-xs text-surface-500">Lost Deals</p>
        </div>
      </div>

      <!-- Funnel visualization -->
      <div v-if="data.funnel?.length" class="space-y-2">
        <div
          v-for="(stage, i) in data.funnel" :key="stage.stage"
          class="flex items-center gap-3"
        >
          <!-- Stage icon -->
          <div :class="['w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0', stageBgs[stage.stage]]">
            <span class="material-symbols-rounded text-lg" :class="stageColors[stage.stage]">{{ stageIcons[stage.stage] }}</span>
          </div>

          <!-- Stage info + bar -->
          <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between mb-1">
              <div class="flex items-center gap-2">
                <span class="text-sm font-medium text-surface-900 dark:text-white">{{ stageLabels[stage.stage] || stage.stage }}</span>
                <span class="text-xs text-surface-400">{{ stage.count }} deals</span>
              </div>
              <div class="flex items-center gap-3 text-xs">
                <span v-if="stage.avg_days_in_stage !== null" class="text-surface-400">
                  ~{{ stage.avg_days_in_stage }}d avg
                </span>
                <span v-if="i > 0" :class="stage.conversion_from_previous >= 70 ? 'text-green-600 dark:text-green-400' : stage.conversion_from_previous >= 40 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400'" class="font-semibold">
                  {{ stage.conversion_from_previous }}%
                </span>
              </div>
            </div>

            <!-- Funnel bar (narrowing) -->
            <div class="w-full bg-surface-100 dark:bg-surface-700 rounded-full h-6 overflow-hidden">
              <div
                :class="['h-6 rounded-full transition-all flex items-center justify-center', {
                  'bg-blue-500/20': stage.stage === 'lead',
                  'bg-indigo-500/25': stage.stage === 'contacted',
                  'bg-purple-500/30': stage.stage === 'proposal',
                  'bg-amber-500/35': stage.stage === 'negotiation',
                  'bg-green-500/40': stage.stage === 'won',
                }]"
                :style="{ width: `${Math.max((stage.count / maxFunnelCount) * 100, stage.count > 0 ? 8 : 0)}%` }"
              >
                <span v-if="stage.conversion_from_lead > 0" class="text-[10px] font-medium text-surface-700 dark:text-surface-200">
                  {{ stage.conversion_from_lead }}% from lead
                </span>
              </div>
            </div>
          </div>

          <!-- Drop-off arrow (between stages) -->
          <div v-if="i < data.funnel.length - 1" class="absolute right-0" style="display: none">
          </div>
        </div>
      </div>

      <div v-else class="text-center py-12 text-surface-400">
        <span class="material-symbols-rounded text-4xl">filter_alt</span>
        <p class="text-sm mt-2">No deal data for funnel analysis</p>
      </div>

      <!-- Source note -->
      <p v-if="data.source === 'snapshot'" class="text-xs text-surface-400 mt-4 italic">
        Based on current deal snapshot. Stage history tracking will provide more accurate data once enabled.
      </p>
    </template>
  </div>
</template>

