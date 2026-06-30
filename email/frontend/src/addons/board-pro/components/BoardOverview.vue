<script setup>
/**
 * BoardOverview - Consolidated board overview combining:
 * - Client info (from Work Breakdown / client-view)
 * - Progress & completion stats (from Executive Report)
 * - Revenue projection (from Executive Report)
 * - Stage breakdown (from Work Breakdown)
 * - Team workload (merged from both)
 * - AI summary & risk analysis (from Executive Report)
 */
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/services/api'
import { useBoardProStore } from '../stores/boardPro'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'

const store = useBoardProStore()
const boardsStore = useBoardsStore()

const loading = ref(true)
const showProgressModal = ref(false)

const report = computed(() => store.executiveReport)
const projection = computed(() => store.revenueProjection)
const workload = computed(() => store.workloadAnalytics)
const clientData = computed(() => store.clientViewData || { client: null, lists: [], members: [], totals: {} })
const summary = computed(() => store.aiSummary)
const risks = computed(() => store.aiRisks)
const aiLoading = computed(() => store.aiLoading)

async function loadAll() {
  const boardId = boardsStore.currentBoard?.id
  if (!boardId) return
  loading.value = true
  try {
    await Promise.all([
      store.fetchClientView(boardId),
      store.fetchExecutiveReport(boardId),
      store.fetchRevenueProjection(boardId),
      store.fetchWorkloadAnalytics(boardId),
    ])
  } catch (e) {
    console.error('[BoardOverview] load error:', e)
  } finally {
    loading.value = false
  }
}

onMounted(loadAll)
watch(() => boardsStore.currentBoard?.id, loadAll)

async function summarize() {
  const boardId = boardsStore.currentBoard?.id
  if (!boardId) return
  await store.aiSummarize(boardId)
  await store.aiRiskReport(boardId)
}

async function exportHtml() {
  const boardId = boardsStore.currentBoard?.id
  if (!boardId) return
  try {
    const res = await api.get(`/board-pro/boards/${boardId}/executive-report?format=html`, { responseType: 'text' })
    const html = typeof res.data === 'string' ? res.data : JSON.stringify(res.data, null, 2)
    const blob = new Blob([html], { type: 'text/html' })
    const url = URL.createObjectURL(blob)
    window.open(url, '_blank')
    setTimeout(() => URL.revokeObjectURL(url), 10000)
  } catch {
    const { useToastStore } = await import('@/stores/toast')
    useToastStore().error('Failed to export report')
  }
}

function riskClass(severity) {
  switch (severity?.toUpperCase()) {
    case 'HIGH': return 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/10 text-red-800 dark:text-red-300'
    case 'MEDIUM': return 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/10 text-amber-800 dark:text-amber-300'
    case 'LOW': return 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/10 text-green-800 dark:text-green-300'
    default: return 'border-surface-200 dark:border-surface-700'
  }
}
</script>

<template>
  <div class="p-6 overflow-auto h-full">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-primary-500 to-violet-500 flex items-center justify-center">
          <span class="material-symbols-rounded text-white text-xl">space_dashboard</span>
        </div>
        <div>
          <h2 class="text-lg font-semibold text-surface-900 dark:text-white">Board Overview</h2>
          <p class="text-sm text-surface-500">Progress, workload, velocity, and client details</p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <button
          @click="exportHtml"
          class="px-3 py-1.5 rounded-full text-sm font-medium flex items-center gap-1.5 transition-colors text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700"
        >
          <span class="material-symbols-rounded text-lg">description</span>
          Export
        </button>
        <button
          @click="summarize"
          :disabled="aiLoading"
          class="px-3 py-1.5 rounded-full text-sm font-medium flex items-center gap-1.5 transition-colors bg-primary-500 text-white hover:bg-primary-600 disabled:opacity-50"
        >
          <span class="material-symbols-rounded text-lg">auto_awesome</span>
          AI Summary
        </button>
        <button
          @click="loadAll"
          class="px-3 py-1.5 rounded-full text-sm font-medium flex items-center gap-1.5 transition-colors text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700"
        >
          <span class="material-symbols-rounded text-lg">refresh</span>
        </button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="animate-spin w-8 h-8 border-3 border-primary-500 border-t-transparent rounded-full"></div>
    </div>

    <template v-else>
      <!-- Client Badge -->
      <div
        v-if="clientData.client"
        class="flex items-center gap-3 p-4 rounded-xl border border-primary-200 dark:border-primary-800 bg-primary-50/50 dark:bg-primary-900/10 mb-6"
      >
        <span class="material-symbols-rounded text-xl text-primary-500">domain</span>
        <div>
          <p class="text-sm font-semibold text-surface-800 dark:text-surface-200">{{ clientData.client.name }}</p>
          <p class="text-xs text-surface-500">{{ clientData.client.domain }}</p>
        </div>
      </div>

      <!-- Progress KPIs -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div class="rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-[rgb(var(--color-surface))] p-4 text-center">
          <p class="text-2xl font-bold text-surface-800 dark:text-surface-200">{{ report?.progress?.total_cards ?? clientData.totals?.card_count ?? 0 }}</p>
          <p class="text-xs text-surface-500">Total Cards</p>
        </div>
        <div class="rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-[rgb(var(--color-surface))] p-4 text-center">
          <p class="text-2xl font-bold text-green-600">{{ report?.progress?.completed ?? clientData.totals?.completed_count ?? 0 }}</p>
          <p class="text-xs text-surface-500">Completed</p>
        </div>
        <div class="rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-[rgb(var(--color-surface))] p-4 text-center">
          <p class="text-2xl font-bold text-red-600">{{ report?.progress?.overdue ?? 0 }}</p>
          <p class="text-xs text-surface-500">Overdue</p>
        </div>
        <div class="rounded-xl border border-surface-200 dark:border-surface-700 bg-white dark:bg-[rgb(var(--color-surface))] p-4 text-center">
          <p class="text-2xl font-bold text-primary-500">{{ report?.progress?.completion_percent ?? 0 }}%</p>
          <p class="text-xs text-surface-500">Complete</p>
        </div>
      </div>

      <!-- Completion Bar -->
      <div v-if="report?.progress" class="mb-6">
        <div class="w-full h-2.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
          <div
            class="h-full bg-primary-500 rounded-full transition-all duration-500"
            :style="{ width: report.progress.completion_percent + '%' }"
          ></div>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-6">
        <!-- Velocity -->
        <div v-if="projection" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-3 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-green-500">speed</span>
            Velocity
          </h3>
          <div class="grid grid-cols-3 gap-4">
            <div>
              <p class="text-xs text-surface-500">Weekly Velocity</p>
              <p class="text-lg font-bold text-surface-800 dark:text-surface-200">{{ projection.weekly_velocity }} cards/wk</p>
            </div>
            <div>
              <p class="text-xs text-surface-500">Remaining</p>
              <p class="text-lg font-bold text-surface-800 dark:text-surface-200">{{ projection.remaining_cards }} cards</p>
            </div>
            <div>
              <p class="text-xs text-surface-500">Est. Completion</p>
              <p class="text-lg font-bold" :class="projection.projected_completion_date ? 'text-green-600' : 'text-surface-400'">
                {{ projection.projected_completion_date || 'N/A' }}
              </p>
            </div>
          </div>
        </div>

        <!-- Stage Breakdown -->
        <div v-if="clientData.lists?.length" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5">
          <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-3 flex items-center gap-2">
            <span class="material-symbols-rounded text-lg text-violet-500">view_column</span>
            Stage Breakdown
          </h3>
          <div class="divide-y divide-surface-100 dark:divide-surface-700">
            <div
              v-for="list in clientData.lists" :key="list.list_id"
              class="py-2 flex items-center gap-3"
            >
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-surface-800 dark:text-surface-200">{{ list.list_name }}</p>
              </div>
              <div class="flex items-center gap-4 text-xs shrink-0">
                <span class="text-surface-500">{{ list.card_count }} cards</span>
                <span class="text-green-600">{{ list.completed_count }} done</span>
              </div>
              <div class="w-16 h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden shrink-0">
                <div
                  class="h-full bg-green-500 rounded-full"
                  :style="{ width: (list.card_count > 0 ? (list.completed_count / list.card_count * 100) : 0) + '%' }"
                ></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Team Workload -->
      <div v-if="(workload && workload.length > 0) || (clientData.members?.length > 0)" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5 mb-6">
        <h3 class="text-sm font-semibold text-surface-900 dark:text-white mb-3 flex items-center gap-2">
          <span class="material-symbols-rounded text-lg text-teal-500">people</span>
          Team Workload
        </h3>
        <div class="space-y-2">
          <div
            v-for="member in (workload?.length ? workload : clientData.members)" :key="member.email || member.member"
            class="flex items-center gap-3"
          >
            <div class="flex-1 min-w-0">
              <p class="text-sm text-surface-800 dark:text-surface-200 truncate">{{ member.email || member.member }}</p>
            </div>
            <div class="flex items-center gap-4 text-xs shrink-0">
              <span class="text-surface-500">{{ member.assigned || member.card_count }} assigned</span>
              <span class="text-green-600">{{ member.completed || member.completed_count }} done</span>
              <span v-if="Number(member.overdue || member.overdue_count) > 0" class="text-red-600">{{ member.overdue || member.overdue_count }} overdue</span>
            </div>
            <div class="w-24 h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden shrink-0">
              <div
                class="h-full rounded-full"
                :class="Number(member.overdue || member.overdue_count) > 0 ? 'bg-red-500' : 'bg-green-500'"
                :style="{ width: ((member.assigned || member.card_count) > 0 ? ((member.completed || member.completed_count) / (member.assigned || member.card_count) * 100) : 0) + '%' }"
              ></div>
            </div>
          </div>
        </div>
      </div>

      <!-- AI Summary -->
      <div v-if="summary" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5 mb-6">
        <div class="flex items-center gap-2 mb-2">
          <span class="material-symbols-rounded text-base text-purple-500">auto_awesome</span>
          <h3 class="text-sm font-semibold text-surface-900 dark:text-white">AI Summary</h3>
          <span v-if="summary.cached" class="text-xs text-surface-400">(cached)</span>
        </div>
        <p class="text-sm text-surface-700 dark:text-surface-300 whitespace-pre-line">{{ summary.summary }}</p>
        <p class="text-xs text-surface-400 mt-2">Generated: {{ summary.generated_at }}</p>
      </div>

      <!-- AI Risk Analysis -->
      <div v-if="risks?.risks" class="bg-white dark:bg-[rgb(var(--color-surface))] rounded-xl border border-surface-200 dark:border-[rgb(var(--color-border))] p-5">
        <div class="flex items-center gap-2 mb-3">
          <span class="material-symbols-rounded text-base text-amber-500">warning</span>
          <h3 class="text-sm font-semibold text-surface-900 dark:text-white">Risk Analysis</h3>
        </div>
        <div class="space-y-2">
          <div
            v-for="(risk, i) in risks.risks" :key="i"
            :class="['p-3 rounded-lg border text-sm', riskClass(risk.severity)]"
          >
            <div class="flex items-center gap-1 mb-1">
              <span class="text-xs font-bold uppercase">{{ risk.severity }}</span>
            </div>
            <p>{{ risk.description }}</p>
            <p v-if="risk.action" class="text-xs mt-1 opacity-75">Action: {{ risk.action }}</p>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
