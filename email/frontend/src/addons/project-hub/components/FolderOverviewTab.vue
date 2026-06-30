<script setup>
import { ref, computed } from 'vue'
import { useProjectHubStore } from '@/addons/project-hub/stores/projectHub'
import { useToastStore } from '@/stores/toast'
import api from '@/services/api'

const emit = defineEmits(['open-card', 'select-board'])

const hubStore = useProjectHubStore()
const toast = useToastStore()

const overview = computed(() => hubStore.folderOverview)
const loading = computed(() => hubStore.folderOverviewLoading)

const boards = computed(() => overview.value?.boards || [])
const recentCards = computed(() => overview.value?.recent_cards || [])
const totalCards = computed(() => overview.value?.total_cards || 0)
const completedCards = computed(() => overview.value?.completed_cards || 0)
const progressPct = computed(() => totalCards.value > 0 ? Math.round((completedCards.value / totalCards.value) * 100) : 0)

const clientId = computed(() => hubStore.activeSpace?.client_id || null)
const callingBoardId = ref(null)

function fmtDate(dateStr) {
  if (!dateStr) return ''
  return new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function openBoard(boardId) {
  emit('select-board', boardId)
}

function openCard(card) {
  emit('open-card', card)
}

async function quickCall(boardId) {
  if (!clientId.value) return
  callingBoardId.value = boardId
  try {
    const res = await api.post(`/clients/${clientId.value}/portal/calls`, {
      call_type: 'instant',
      board_id: boardId,
    })
    if (res.data?.success) {
      const call = res.data.data
      const linkRes = await api.post(`/clients/${clientId.value}/portal/calls/${call.id}/guest-link`, {
        ttl_hours: 4, max_uses: 0, role: 'admin',
      })
      if (linkRes.data?.success && linkRes.data.data?.link) {
        window.open(linkRes.data.data.link, '_blank')
        toast.success('Call started -- time will be tracked to this board')
      } else {
        toast.success('Call room created')
      }
    }
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to create call')
  } finally {
    callingBoardId.value = null
  }
}
</script>

<template>
  <div>
    <div v-if="loading && !overview" class="flex items-center justify-center py-16">
      <span class="material-symbols-rounded text-3xl text-surface-400 animate-spin">progress_activity</span>
    </div>

    <template v-else>
      <!-- Inline summary line -->
      <div class="flex items-center gap-3 mb-5 text-xs text-surface-400">
        <span>{{ totalCards }} tasks</span>
        <span class="text-surface-300">|</span>
        <span class="text-green-500 font-medium">{{ completedCards }} done</span>
        <span class="text-surface-300">|</span>
        <div class="flex items-center gap-1.5">
          <div class="w-24 h-1.5 bg-surface-200 dark:bg-surface-700 rounded-full overflow-hidden">
            <div class="h-full rounded-full bg-primary-500 transition-all" :style="{ width: progressPct + '%' }"></div>
          </div>
          <span class="font-medium text-primary-500">{{ progressPct }}%</span>
        </div>
      </div>

      <!-- Boards (full-width hero) -->
      <div class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex items-center gap-2">
          <span class="material-symbols-rounded text-primary-500 text-lg">view_kanban</span>
          <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Boards</h3>
          <span class="text-xs text-surface-400 ml-auto">{{ boards.length }} boards</span>
        </div>
        <div class="divide-y divide-surface-100 dark:divide-surface-700/50">
          <div v-if="boards.length === 0" class="text-center py-10 text-surface-400 text-sm">
            <span class="material-symbols-rounded text-3xl block mb-2">view_kanban</span>
            No boards linked yet
          </div>
          <div
            v-for="board in boards" :key="board.board_id"
            class="flex items-center gap-3 px-4 py-3 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
          >
            <button class="flex-1 min-w-0 text-left" @click="openBoard(board.board_id)">
              <div class="flex items-center justify-between mb-1.5">
                <span class="text-sm font-medium text-surface-800 dark:text-surface-100 truncate">{{ board.board_name || board.name }}</span>
                <span class="text-xs text-surface-400 tabular-nums ml-2">{{ board.completed_cards }}/{{ board.total_cards }}</span>
              </div>
              <div class="h-1.5 bg-surface-100 dark:bg-surface-700 rounded-full overflow-hidden">
                <div
                  class="h-full rounded-full transition-all bg-primary-500"
                  :style="{ width: `${board.total_cards > 0 ? (board.completed_cards / board.total_cards * 100) : 0}%` }"
                ></div>
              </div>
            </button>
            <button
              v-if="clientId"
              @click.stop="quickCall(board.board_id)"
              :disabled="callingBoardId === board.board_id"
              class="shrink-0 flex items-center gap-1 px-2.5 py-1.5 rounded-full text-xs font-medium bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400 hover:bg-green-100 dark:hover:bg-green-500/20 transition-colors disabled:opacity-50"
              title="Start a tracked video call for this board"
            >
              <span class="material-symbols-rounded text-sm">{{ callingBoardId === board.board_id ? 'progress_activity' : 'videocam' }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Recent Activity (compact) -->
      <div v-if="recentCards.length > 0" class="bg-white dark:bg-surface-800 rounded-xl border border-surface-200 dark:border-surface-700 overflow-hidden">
        <div class="px-4 py-3 border-b border-surface-200 dark:border-surface-700 flex items-center gap-2">
          <span class="material-symbols-rounded text-surface-400 text-lg">history</span>
          <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">Recent Activity</h3>
        </div>
        <div class="divide-y divide-surface-100 dark:divide-surface-700/50 max-h-56 overflow-y-auto">
          <button
            v-for="card in recentCards" :key="card.id"
            class="w-full text-left flex items-center gap-3 px-4 py-2.5 hover:bg-surface-50 dark:hover:bg-surface-700/50 transition-colors"
            @click="openCard({ id: card.id, board_id: card.board_id })"
          >
            <span class="material-symbols-rounded text-base" :class="card.completed ? 'text-green-500' : 'text-surface-300'">
              {{ card.completed ? 'check_circle' : 'radio_button_unchecked' }}
            </span>
            <div class="flex-1 min-w-0">
              <div class="text-sm text-surface-800 dark:text-surface-100 truncate">{{ card.title }}</div>
              <div class="text-xs text-surface-400">{{ card.board_name }}</div>
            </div>
            <span v-if="card.updated_at" class="text-xs text-surface-400 tabular-nums">{{ fmtDate(card.updated_at) }}</span>
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
