<script setup>
/**
 * BoardScopeRadar - Scope creep detection view for boards.
 * Shows time creep, activity spikes, and flags cards/clients exceeding budget.
 */
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/services/api'
import { useToastStore } from '@/stores/toast'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'
import { useBoardProStore } from '../stores/boardPro'

const toast = useToastStore()
const boardsStore = useBoardsStore()
const boardProStore = useBoardProStore()

const loading = ref(true)
const radarData = ref(null)

const timeLoading = computed(() => boardProStore.lensViewLoading)
const timeData = computed(() => boardProStore.timeViewData || [])

async function loadRadar() {
  const boardId = boardsStore.currentBoard?.id
  if (!boardId) return
  loading.value = true
  try {
    const [res] = await Promise.all([
      api.get(`/board-pro/boards/${boardId}/scope-radar`),
      boardProStore.fetchTimeView(boardId),
    ])
    if (res.data?.success) radarData.value = res.data.data
  } catch (e) {
    toast.error('Failed to load scope radar data')
  } finally {
    loading.value = false
  }
}

onMounted(loadRadar)
watch(() => boardsStore.currentBoard?.id, loadRadar)

const summary = computed(() => radarData.value?.summary || {})
const cards = computed(() => radarData.value?.cards || [])
const activitySpikes = computed(() => radarData.value?.activity_spikes || {})

const flaggedCards = computed(() =>
  cards.value.filter(c => c.flags.length > 0).sort((a, b) => {
    const sev = { critical: 3, high: 2, warning: 1, normal: 0 }
    return (sev[b.severity] || 0) - (sev[a.severity] || 0)
  })
)

const normalCards = computed(() =>
  cards.value.filter(c => c.flags.length === 0 && !c.completed)
)

const maxDailyActivity = computed(() => {
  const daily = activitySpikes.value?.daily || []
  if (!daily.length) return 1
  return Math.max(...daily.map(d => parseInt(d.activity_count)), 1)
})

const kpiCards = computed(() => {
  const s = summary.value
  return [
    {
      label: 'Flagged Cards',
      value: s.flagged_cards ?? 0,
      icon: 'flag',
      color: 'text-red-500',
      bg: 'bg-red-50 dark:bg-red-500/10',
      border: 'border-red-200 dark:border-red-500/20',
    },
    {
      label: 'Time Exceeded',
      value: s.time_exceeded_count ?? 0,
      icon: 'timer_off',
      color: 'text-orange-500',
      bg: 'bg-orange-50 dark:bg-orange-500/10',
      border: 'border-orange-200 dark:border-orange-500/20',
    },
    {
      label: 'Activity Spikes',
      value: s.activity_spiked_count ?? 0,
      icon: 'show_chart',
      color: 'text-purple-500',
      bg: 'bg-purple-50 dark:bg-purple-500/10',
      border: 'border-purple-200 dark:border-purple-500/20',
    },
    {
      label: 'Overdue',
      value: s.overdue_count ?? 0,
      icon: 'event_busy',
      color: 'text-amber-500',
      bg: 'bg-amber-50 dark:bg-amber-500/10',
      border: 'border-amber-200 dark:border-amber-500/20',
    },
    {
      label: 'Board Activity',
      value: `${s.board_activity_spike_pct ?? 0}%`,
      sub: `${s.board_activity_this_week ?? 0} this week (avg ${s.board_activity_avg ?? 0})`,
      icon: 'monitoring',
      color: boardActivityColor(s.board_activity_spike_pct),
      bg: boardActivityBg(s.board_activity_spike_pct),
      border: boardActivityBorder(s.board_activity_spike_pct),
    },
  ]
})

function boardActivityColor(pct) {
  if (pct > 200) return 'text-red-500'
  if (pct > 150) return 'text-orange-500'
  return 'text-green-500'
}

function boardActivityBg(pct) {
  if (pct > 200) return 'bg-red-50 dark:bg-red-500/10'
  if (pct > 150) return 'bg-orange-50 dark:bg-orange-500/10'
  return 'bg-green-50 dark:bg-green-500/10'
}

function boardActivityBorder(pct) {
  if (pct > 200) return 'border-red-200 dark:border-red-500/20'
  if (pct > 150) return 'border-orange-200 dark:border-orange-500/20'
  return 'border-green-200 dark:border-green-500/20'
}

function severityColor(severity) {
  const map = {
    critical: 'text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-500/20',
    high: 'text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-500/10 border-orange-200 dark:border-orange-500/20',
    warning: 'text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-500/10 border-amber-200 dark:border-amber-500/20',
  }
  return map[severity] || 'text-surface-500 bg-surface-50 dark:bg-surface-800 border-surface-200 dark:border-surface-700'
}

function severityLabel(severity) {
  const map = { critical: 'Critical', high: 'High', warning: 'Warning' }
  return map[severity] || 'Normal'
}

function flagIcon(flag) {
  const map = {
    time_exceeded: 'timer_off',
    activity_spike: 'show_chart',
    overdue: 'event_busy',
    todo_overload: 'checklist',
  }
  return map[flag] || 'flag'
}

function flagLabel(flag) {
  const map = {
    time_exceeded: 'Time exceeded',
    activity_spike: 'Activity spike',
    overdue: 'Overdue',
    todo_overload: 'Too many open todos',
  }
  return map[flag] || flag
}

function formatDate(dateStr) {
  if (!dateStr) return '\u2014'
  const d = new Date(dateStr)
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function formatDayLabel(dateStr) {
  const d = new Date(dateStr)
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}
</script>

<template>
  <div class="p-6 overflow-auto h-full">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-red-500 to-orange-500 flex items-center justify-center">
          <span class="material-symbols-rounded text-white text-xl">radar</span>
        </div>
        <div>
          <h2 class="text-lg font-semibold text-surface-900 dark:text-white">Scope Creep Radar</h2>
          <p class="text-sm text-surface-500">Detect cards and tasks exceeding their planned scope</p>
        </div>
      </div>
      <button
        @click="loadRadar"
        class="px-3 py-1.5 rounded-full text-sm font-medium flex items-center gap-1.5 transition-colors text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700"
      >
        <span class="material-symbols-rounded text-lg">refresh</span>
        Refresh
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full"></div>
    </div>

    <template v-else>
      <!-- KPI Cards -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-6">
        <div
          v-for="card in kpiCards" :key="card.label"
          :class="['rounded-xl border p-4', card.bg, card.border]"
        >
          <div class="flex items-center justify-between mb-2">
            <span class="text-[10px] font-semibold text-surface-500 uppercase tracking-wider">{{ card.label }}</span>
            <span class="material-symbols-rounded text-lg" :class="card.color">{{ card.icon }}</span>
          </div>
          <p class="text-xl font-bold text-surface-900 dark:text-white">{{ card.value }}</p>
          <p v-if="card.sub" class="text-[11px] text-surface-400 mt-0.5">{{ card.sub }}</p>
        </div>
      </div>

      <!-- Board severity banner -->
      <div
        v-if="summary.board_severity && summary.board_severity !== 'normal'"
        :class="['rounded-xl border p-4 mb-6 flex items-center gap-3', severityColor(summary.board_severity)]"
      >
        <span class="material-symbols-rounded text-2xl">warning</span>
        <div>
          <p class="font-semibold">
            This board shows signs of scope creep
          </p>
          <p class="text-sm opacity-80">
            {{ summary.flagged_cards }} of {{ summary.total_cards }} cards flagged.
            Board activity is at {{ summary.board_activity_spike_pct }}% of baseline.
          </p>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
        <!-- Activity Trend Chart -->
        <div class="xl:col-span-2 bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-3 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-purple-500">show_chart</span>
            Activity Trend (Last 28 Days)
          </h3>
          <div v-if="activitySpikes.daily?.length" class="flex items-end gap-0.5 h-32">
            <div
              v-for="day in activitySpikes.daily" :key="day.activity_date"
              class="flex-1 flex flex-col items-center gap-0.5"
              :title="`${formatDayLabel(day.activity_date)}: ${day.activity_count} actions, ${day.cards_touched} cards`"
            >
              <div
                class="w-full rounded-t transition-all cursor-pointer"
                :class="parseInt(day.activity_count) > (summary.board_activity_avg || 0) * 1.5 / 7
                  ? 'bg-orange-500 dark:bg-orange-400'
                  : 'bg-primary-400 dark:bg-primary-500'"
                :style="{ height: `${Math.max((parseInt(day.activity_count) / maxDailyActivity) * 100, 3)}%` }"
              ></div>
            </div>
          </div>
          <div v-else class="h-32 flex items-center justify-center text-surface-400 text-sm">
            No activity data available
          </div>
          <div v-if="activitySpikes.daily?.length" class="flex justify-between mt-1 text-[10px] text-surface-400">
            <span>{{ formatDayLabel(activitySpikes.daily[0]?.activity_date) }}</span>
            <span>{{ formatDayLabel(activitySpikes.daily[activitySpikes.daily.length - 1]?.activity_date) }}</span>
          </div>
        </div>

        <!-- Severity Breakdown -->
        <div class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-3 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-red-500">assessment</span>
            Severity Breakdown
          </h3>
          <div class="space-y-3">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full bg-red-500"></span>
                <span class="text-sm text-surface-700 dark:text-surface-300">Critical</span>
              </div>
              <span class="text-sm font-bold text-surface-900 dark:text-white">{{ summary.critical_count || 0 }}</span>
            </div>
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full bg-orange-500"></span>
                <span class="text-sm text-surface-700 dark:text-surface-300">High</span>
              </div>
              <span class="text-sm font-bold text-surface-900 dark:text-white">{{ summary.high_count || 0 }}</span>
            </div>
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full bg-amber-500"></span>
                <span class="text-sm text-surface-700 dark:text-surface-300">Warning</span>
              </div>
              <span class="text-sm font-bold text-surface-900 dark:text-white">{{ summary.warning_count || 0 }}</span>
            </div>
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full bg-green-500"></span>
                <span class="text-sm text-surface-700 dark:text-surface-300">Normal</span>
              </div>
              <span class="text-sm font-bold text-surface-900 dark:text-white">{{ (summary.total_cards || 0) - (summary.flagged_cards || 0) }}</span>
            </div>
          </div>

          <div v-if="summary.total_cards" class="mt-4 pt-3 border-t border-surface-100 dark:border-surface-700">
            <div class="flex rounded-full h-2 overflow-hidden bg-surface-100 dark:bg-surface-700">
              <div class="bg-red-500 transition-all" :style="{ width: `${((summary.critical_count || 0) / summary.total_cards) * 100}%` }"></div>
              <div class="bg-orange-500 transition-all" :style="{ width: `${((summary.high_count || 0) / summary.total_cards) * 100}%` }"></div>
              <div class="bg-amber-500 transition-all" :style="{ width: `${((summary.warning_count || 0) / summary.total_cards) * 100}%` }"></div>
              <div class="bg-green-500 transition-all" :style="{ width: `${(((summary.total_cards || 0) - (summary.flagged_cards || 0)) / summary.total_cards) * 100}%` }"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Flagged Cards -->
      <div v-if="flaggedCards.length" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5 mb-6">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-4 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-red-500">flag</span>
          Flagged Cards
          <span class="text-xs font-normal text-surface-400">({{ flaggedCards.length }})</span>
        </h3>

        <div class="space-y-2">
          <div
            v-for="card in flaggedCards" :key="card.card_id"
            :class="['p-4 rounded-lg border transition-colors', severityColor(card.severity)]"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <span :class="['text-[10px] font-bold uppercase px-2 py-0.5 rounded-full border', severityColor(card.severity)]">
                    {{ severityLabel(card.severity) }}
                  </span>
                  <p class="text-sm font-semibold text-surface-900 dark:text-white truncate">{{ card.card_title }}</p>
                </div>
                <div class="flex items-center gap-3 text-[11px] text-surface-500 mb-2">
                  <span>{{ card.list_name }}</span>
                  <span v-if="card.assigned_to" class="flex items-center gap-1">
                    <span class="material-symbols-rounded text-xs">person</span>
                    {{ card.assigned_to }}
                  </span>
                  <span v-if="card.start_date">{{ formatDate(card.start_date) }} - {{ formatDate(card.due_date) }}</span>
                </div>

                <!-- Flags -->
                <div class="flex flex-wrap gap-1.5">
                  <span
                    v-for="flag in card.flags" :key="flag"
                    class="inline-flex items-center gap-1 text-[10px] font-medium px-2 py-0.5 rounded-full bg-white/50 dark:bg-black/20 border border-current/20"
                  >
                    <span class="material-symbols-rounded text-xs">{{ flagIcon(flag) }}</span>
                    {{ flagLabel(flag) }}
                  </span>
                </div>
              </div>

              <div class="flex items-center gap-4 flex-shrink-0 text-right">
                <!-- Time creep indicator -->
                <div v-if="card.time_creep_pct !== null">
                  <p class="text-lg font-bold" :class="card.time_creep_pct > 100 ? 'text-red-600 dark:text-red-400' : card.time_creep_pct > 80 ? 'text-amber-600' : 'text-green-600'">
                    {{ card.time_creep_pct }}%
                  </p>
                  <p class="text-[10px] text-surface-400">Time used</p>
                </div>

                <!-- Activity spike -->
                <div v-if="card.activity_spike_pct > 100">
                  <p class="text-lg font-bold" :class="card.activity_spike_pct > 150 ? 'text-purple-600 dark:text-purple-400' : 'text-surface-600'">
                    {{ card.activity_spike_pct }}%
                  </p>
                  <p class="text-[10px] text-surface-400">vs avg activity</p>
                </div>

                <!-- Tracked hours -->
                <div v-if="card.tracked_hours > 0">
                  <p class="text-sm font-bold text-surface-700 dark:text-surface-200">{{ card.tracked_hours }}h</p>
                  <p class="text-[10px] text-surface-400">Tracked</p>
                </div>
              </div>
            </div>

            <!-- Progress bars -->
            <div v-if="card.time_creep_pct !== null || card.total_todos > 0" class="mt-3 flex gap-4">
              <div v-if="card.time_creep_pct !== null" class="flex-1">
                <div class="flex items-center justify-between text-[10px] text-surface-500 mb-1">
                  <span>Timeline</span>
                  <span>{{ card.elapsed_days?.toFixed(0) || 0 }}d / {{ card.planned_days?.toFixed(0) || 0 }}d</span>
                </div>
                <div class="w-full bg-white/50 dark:bg-black/20 rounded-full h-1.5">
                  <div
                    class="h-1.5 rounded-full transition-all"
                    :class="card.time_creep_pct > 100 ? 'bg-red-500' : card.time_creep_pct > 80 ? 'bg-amber-500' : 'bg-green-500'"
                    :style="{ width: `${Math.min(card.time_creep_pct, 100)}%` }"
                  ></div>
                </div>
              </div>
              <div v-if="card.total_todos > 0" class="flex-1">
                <div class="flex items-center justify-between text-[10px] text-surface-500 mb-1">
                  <span>Todos</span>
                  <span>{{ card.completed_todos }} / {{ card.total_todos }}</span>
                </div>
                <div class="w-full bg-white/50 dark:bg-black/20 rounded-full h-1.5">
                  <div
                    class="h-1.5 rounded-full bg-blue-500 transition-all"
                    :style="{ width: `${(card.completed_todos / card.total_todos) * 100}%` }"
                  ></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Email Volume -->
      <div v-if="radarData?.email_volume" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5 mb-6">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-3 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-blue-500">mail</span>
          Client Email Volume
        </h3>
        <div class="flex items-center gap-6">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-500/10 flex items-center justify-center">
              <span class="material-symbols-rounded text-blue-500">person</span>
            </div>
            <div>
              <p class="text-sm font-semibold text-surface-900 dark:text-white">{{ radarData.email_volume.client_name }}</p>
              <p class="text-[11px] text-surface-400">{{ radarData.email_volume.contact_count }} contacts</p>
            </div>
          </div>
          <div class="text-center">
            <p class="text-2xl font-bold text-surface-900 dark:text-white">{{ radarData.email_volume.total_emails }}</p>
            <p class="text-[11px] text-surface-400">Total emails (sent + received)</p>
          </div>
          <div v-if="radarData.email_volume.last_email_at" class="text-center">
            <p class="text-sm font-medium text-surface-700 dark:text-surface-200">{{ formatDate(radarData.email_volume.last_email_at) }}</p>
            <p class="text-[11px] text-surface-400">Last email</p>
          </div>
        </div>
      </div>

      <!-- Time Tracking Table -->
      <div v-if="timeData.length" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5 mb-6">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-3 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-blue-500">timer</span>
          Time Tracking
          <span class="text-xs font-normal text-surface-400">({{ timeData.length }} cards)</span>
        </h3>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-xs text-surface-500 dark:text-surface-400 border-b border-surface-200 dark:border-surface-700">
                <th class="text-left py-2 px-3">Card</th>
                <th class="text-left py-2 px-3">Stage</th>
                <th class="text-left py-2 px-3">Assignee</th>
                <th class="text-right py-2 px-3">Budget (h)</th>
                <th class="text-right py-2 px-3">Tracked (h)</th>
                <th class="text-right py-2 px-3">Usage</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-surface-100 dark:divide-surface-700">
              <tr
                v-for="card in timeData" :key="card.card_id"
                class="hover:bg-surface-50 dark:hover:bg-surface-800/50 transition-colors"
              >
                <td class="py-2 px-3 text-surface-800 dark:text-surface-200">{{ card.card_title }}</td>
                <td class="py-2 px-3 text-surface-500 dark:text-surface-400">{{ card.list_name }}</td>
                <td class="py-2 px-3 text-surface-500 dark:text-surface-400">{{ card.assigned_to || '-' }}</td>
                <td class="py-2 px-3 text-right text-surface-800 dark:text-surface-200">{{ card.budget_hours || '-' }}</td>
                <td class="py-2 px-3 text-right font-medium" :class="card.over_budget ? 'text-red-600' : 'text-surface-800 dark:text-surface-200'">
                  {{ card.tracked_hours }}
                </td>
                <td class="py-2 px-3 text-right">
                  <div v-if="card.budget_hours > 0" class="flex items-center justify-end gap-2">
                    <div class="w-16 h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
                      <div
                        class="h-full rounded-full transition-all"
                        :class="card.over_budget ? 'bg-red-500' : card.budget_used_percent > 80 ? 'bg-amber-500' : 'bg-green-500'"
                        :style="{ width: Math.min(card.budget_used_percent, 100) + '%' }"
                      ></div>
                    </div>
                    <span class="text-xs" :class="card.over_budget ? 'text-red-600' : 'text-surface-500'">
                      {{ card.budget_used_percent }}%
                    </span>
                  </div>
                  <span v-else class="text-xs text-surface-400">-</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Normal Cards (collapsible) -->
      <div v-if="normalCards.length" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-3 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-green-500">check_circle</span>
          On Track
          <span class="text-xs font-normal text-surface-400">({{ normalCards.length }})</span>
        </h3>
        <div class="space-y-1">
          <div
            v-for="card in normalCards" :key="card.card_id"
            class="flex items-center gap-3 p-2 rounded-lg hover:bg-surface-50 dark:hover:bg-[rgb(var(--color-surface-hover))] transition-colors"
          >
            <span class="w-2 h-2 rounded-full bg-green-500 flex-shrink-0"></span>
            <span class="text-sm text-surface-700 dark:text-surface-300 truncate flex-1">{{ card.card_title }}</span>
            <span class="text-xs text-surface-400">{{ card.list_name }}</span>
            <span v-if="card.time_creep_pct !== null" class="text-xs text-surface-400">{{ card.time_creep_pct }}%</span>
            <span v-if="card.tracked_hours > 0" class="text-xs text-surface-400">{{ card.tracked_hours }}h</span>
          </div>
        </div>
      </div>

      <!-- Empty state -->
      <div v-if="!cards.length" class="text-center py-16">
        <div class="w-16 h-16 mx-auto rounded-full bg-green-50 dark:bg-green-500/10 flex items-center justify-center mb-3">
          <span class="material-symbols-rounded text-3xl text-green-500">verified</span>
        </div>
        <h3 class="text-lg font-semibold text-surface-900 dark:text-white mb-1">All Clear</h3>
        <p class="text-sm text-surface-500">No cards on this board to analyze. Add cards with start and due dates to enable scope tracking.</p>
      </div>
    </template>
  </div>
</template>
