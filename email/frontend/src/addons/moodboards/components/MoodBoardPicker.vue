<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm" @click.self="$emit('close')">
    <div class="bg-white dark:bg-surface-800 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] flex flex-col overflow-hidden border border-surface-200 dark:border-surface-700">
      <!-- Header -->
      <div class="px-6 py-4 border-b border-surface-200 dark:border-surface-700 flex items-center justify-between">
        <div>
          <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-100">Add from Boards</h2>
          <p class="text-xs text-surface-500 mt-0.5">Add an entire board or a specific card to your mood board</p>
        </div>
        <button @click="$emit('close')" class="p-1.5 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-700 text-surface-500">
          <span class="material-symbols-rounded text-lg">close</span>
        </button>
      </div>

      <!-- Search -->
      <div class="px-6 py-3 border-b border-surface-200 dark:border-surface-700">
        <div class="relative">
          <span class="material-symbols-rounded text-lg absolute left-3 top-1/2 -translate-y-1/2 text-surface-400">search</span>
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Search boards and cards..."
            class="w-full pl-10 pr-4 py-2 rounded-xl bg-surface-50 dark:bg-surface-900 border border-surface-200 dark:border-surface-700 text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 text-surface-900 dark:text-surface-100"
            ref="searchInput"
          />
        </div>
      </div>

      <!-- Mode tabs -->
      <div class="px-6 py-2 border-b border-surface-200 dark:border-surface-700 flex gap-1">
        <button
          @click="mode = 'boards'"
          :class="[
            'px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
            mode === 'boards' ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
          ]"
        >
          <span class="material-symbols-rounded text-sm align-middle mr-1">dashboard</span>
          Boards
        </button>
        <button
          @click="mode = 'cards'"
          :class="[
            'px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
            mode === 'cards' ? 'bg-primary-100 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' : 'text-surface-500 hover:bg-surface-100 dark:hover:bg-surface-700'
          ]"
        >
          <span class="material-symbols-rounded text-sm align-middle mr-1">credit_card</span>
          Cards
        </button>
      </div>

      <!-- Content -->
      <div class="flex-1 overflow-auto p-4">
        <!-- Loading -->
        <div v-if="loading" class="flex items-center justify-center py-12">
          <span class="material-symbols-rounded text-2xl text-surface-400 animate-spin">sync</span>
        </div>

        <!-- BOARDS MODE -->
        <template v-else-if="mode === 'boards'">
          <div v-if="filteredBoards.length === 0" class="text-center py-12 text-surface-400">
            <span class="material-symbols-rounded text-3xl">dashboard</span>
            <p class="text-sm mt-2">No boards found</p>
          </div>
          <div v-else class="grid grid-cols-2 gap-3">
            <button
              v-for="board in filteredBoards"
              :key="board.id"
              @click="selectBoard(board)"
              class="text-left p-4 rounded-xl border border-surface-200 dark:border-surface-700 hover:border-primary-300 dark:hover:border-primary-700 hover:bg-primary-50/50 dark:hover:bg-primary-900/10 transition-all group"
            >
              <div class="flex items-center gap-3 mb-2">
                <div
                  class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-sm font-bold flex-shrink-0"
                  :style="{ backgroundColor: board.background_color || '#6366f1' }"
                >
                  {{ (board.name || 'B').charAt(0).toUpperCase() }}
                </div>
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-medium text-surface-900 dark:text-surface-100 truncate">{{ board.name }}</p>
                  <p class="text-xs text-surface-500">{{ board.lists?.length || 0 }} lists · {{ countCards(board) }} cards</p>
                </div>
              </div>
              <div v-if="board.description" class="text-xs text-surface-500 truncate">{{ board.description }}</div>
            </button>
          </div>
        </template>

        <!-- CARDS MODE -->
        <template v-else-if="mode === 'cards'">
          <!-- Board selector -->
          <div v-if="!selectedBoardForCards" class="space-y-2">
            <p class="text-xs font-medium text-surface-500 mb-3">Select a board to browse its cards:</p>
            <button
              v-for="board in filteredBoards"
              :key="board.id"
              @click="loadBoardCards(board)"
              class="w-full text-left px-4 py-3 rounded-xl border border-surface-200 dark:border-surface-700 hover:border-primary-300 dark:hover:border-primary-700 hover:bg-primary-50/50 dark:hover:bg-primary-900/10 transition-all flex items-center gap-3"
            >
              <div
                class="w-6 h-6 rounded flex items-center justify-center text-white text-xs font-bold flex-shrink-0"
                :style="{ backgroundColor: board.background_color || '#6366f1' }"
              >
                {{ (board.name || 'B').charAt(0).toUpperCase() }}
              </div>
              <span class="text-sm font-medium text-surface-900 dark:text-surface-100">{{ board.name }}</span>
              <span class="text-xs text-surface-400 ml-auto">{{ countCards(board) }} cards</span>
              <span class="material-symbols-rounded text-sm text-surface-400">chevron_right</span>
            </button>
          </div>

          <!-- Card list for selected board -->
          <div v-else>
            <button
              @click="selectedBoardForCards = null; boardCards = []"
              class="flex items-center gap-1 text-xs text-primary-500 hover:text-primary-600 mb-3"
            >
              <span class="material-symbols-rounded text-sm">arrow_back</span>
              Back to boards
            </button>

            <div class="flex items-center gap-2 mb-4">
              <div
                class="w-6 h-6 rounded flex items-center justify-center text-white text-xs font-bold"
                :style="{ backgroundColor: selectedBoardForCards.background_color || '#6366f1' }"
              >
                {{ (selectedBoardForCards.name || 'B').charAt(0).toUpperCase() }}
              </div>
              <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-100">{{ selectedBoardForCards.name }}</h3>
            </div>

            <div v-if="loadingCards" class="flex items-center justify-center py-8">
              <span class="material-symbols-rounded text-xl text-surface-400 animate-spin">sync</span>
            </div>

            <div v-else-if="filteredCards.length === 0" class="text-center py-8 text-surface-400">
              <span class="material-symbols-rounded text-2xl">credit_card</span>
              <p class="text-sm mt-2">No cards found</p>
            </div>

            <!-- Cards grouped by list -->
            <div v-else class="space-y-4">
              <div v-for="list in cardsByList" :key="list.id">
                <h4 class="text-xs font-semibold text-surface-500 uppercase tracking-wider mb-2 px-1">{{ list.name }}</h4>
                <div class="space-y-1.5">
                  <button
                    v-for="card in list.cards"
                    :key="card.id"
                    @click="selectCard(card)"
                    class="w-full text-left px-3 py-2.5 rounded-xl border border-surface-200 dark:border-surface-700 hover:border-primary-300 dark:hover:border-primary-700 hover:bg-primary-50/50 dark:hover:bg-primary-900/10 transition-all"
                  >
                    <div class="flex items-center gap-2">
                      <!-- Labels -->
                      <div v-if="card.labels?.length" class="flex gap-0.5 flex-shrink-0">
                        <div
                          v-for="label in card.labels.slice(0, 3)"
                          :key="label.id"
                          class="w-1.5 h-4 rounded-full"
                          :style="{ backgroundColor: label.color }"
                          :title="label.name"
                        />
                      </div>
                      <span class="text-sm text-surface-900 dark:text-surface-100 truncate">{{ card.title }}</span>
                    </div>
                    <!-- Card meta -->
                    <div class="flex items-center gap-3 mt-1.5 text-[11px] text-surface-400">
                      <span v-if="card.due_date" class="flex items-center gap-0.5" :class="isOverdue(card) ? 'text-red-500' : ''">
                        <span class="material-symbols-rounded text-[12px]">schedule</span>
                        {{ formatDate(card.due_date) }}
                      </span>
                      <span v-if="card.checklists?.length" class="flex items-center gap-0.5">
                        <span class="material-symbols-rounded text-[12px]">checklist</span>
                        {{ getChecklistProgress(card) }}
                      </span>
                      <span v-if="card.assignees?.length" class="flex items-center gap-0.5">
                        <span class="material-symbols-rounded text-[12px]">person</span>
                        {{ card.assignees.length }}
                      </span>
                      <span v-if="card.attachment_count" class="flex items-center gap-0.5">
                        <span class="material-symbols-rounded text-[12px]">attach_file</span>
                        {{ card.attachment_count }}
                      </span>
                    </div>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { useBoardsStore } from '@/addons/kanban-boards/stores/boards'

const emit = defineEmits(['select-board', 'select-card', 'close'])

const boardsStore = useBoardsStore()

const searchInput = ref(null)
const searchQuery = ref('')
const mode = ref('boards') // 'boards' or 'cards'
const loading = ref(false)
const loadingCards = ref(false)
const selectedBoardForCards = ref(null)
const boardCards = ref([])

// Fetch boards on mount
onMounted(async () => {
  loading.value = true
  try {
    await boardsStore.fetchBoards()
  } finally {
    loading.value = false
  }
  await nextTick()
  searchInput.value?.focus()
})

const filteredBoards = computed(() => {
  const q = searchQuery.value.toLowerCase().trim()
  const boards = boardsStore.activeBoards || []
  if (!q) return boards
  return boards.filter(b =>
    (b.name || '').toLowerCase().includes(q) ||
    (b.description || '').toLowerCase().includes(q)
  )
})

const filteredCards = computed(() => {
  const q = searchQuery.value.toLowerCase().trim()
  if (!q) return boardCards.value
  return boardCards.value.filter(c =>
    (c.title || '').toLowerCase().includes(q) ||
    (c.description || '').toLowerCase().includes(q)
  )
})

const cardsByList = computed(() => {
  if (!selectedBoardForCards.value?.lists) return []
  const lists = selectedBoardForCards.value.lists
  return lists
    .map(list => ({
      ...list,
      cards: filteredCards.value.filter(c => c.list_id === list.id)
    }))
    .filter(list => list.cards.length > 0)
})

function countCards(board) {
  if (!board.lists) return 0
  return board.lists.reduce((sum, list) => sum + (list.cards?.length || 0), 0)
}

async function selectBoard(board) {
  // Fetch full board data with lists and cards
  loading.value = true
  try {
    const fullBoard = await boardsStore.fetchBoard(board.id, { silent: true })
    const b = fullBoard || board

    emit('select-board', {
      type: 'board_link',
      linked_board_id: b.id,
      title: b.name,
      content: b.description || '',
      color: b.background_color || '#6366f1',
      board_data: {
        id: b.id,
        name: b.name,
        description: b.description || '',
        background_color: b.background_color,
        lists: (b.lists || []).map(l => ({
          id: l.id,
          name: l.name,
          card_count: l.cards?.length || 0,
          cards: (l.cards || []).slice(0, 10).map(c => ({
            id: c.id,
            title: c.title,
            labels: c.labels || [],
            due_date: c.due_date,
            completed: c.completed || false,
            description: c.description || '',
            checklist_total: getCardChecklistTotal(c),
            checklist_done: getCardChecklistDone(c),
            assignees: c.assignees || [],
            comment_count: c.comment_count || 0
          }))
        })),
        total_cards: countCards(b)
      }
    })
  } finally {
    loading.value = false
  }
}

function getCardChecklistTotal(card) {
  if (!card.checklists?.length) return 0
  return card.checklists.reduce((sum, cl) => sum + (cl.items?.length || 0), 0)
}

function getCardChecklistDone(card) {
  if (!card.checklists?.length) return 0
  return card.checklists.reduce((sum, cl) =>
    sum + (cl.items || []).filter(i => i.completed || i.checked).length, 0)
}

async function loadBoardCards(board) {
  selectedBoardForCards.value = board
  loadingCards.value = true
  try {
    // Fetch the full board data with cards
    const fullBoard = await boardsStore.fetchBoard(board.id, { silent: true })
    if (fullBoard) {
      selectedBoardForCards.value = fullBoard
      // Flatten cards from all lists
      boardCards.value = (fullBoard.lists || []).flatMap(list =>
        (list.cards || []).map(card => ({
          ...card,
          list_id: list.id,
          list_name: list.name
        }))
      )
    }
  } finally {
    loadingCards.value = false
  }
}

async function selectCard(card) {
  // Fetch full card data (including checklists with items) from the API
  const fullCard = await boardsStore.getCard(card.id)
  const cardData = fullCard || card
  
  const checklistItems = []
  if (cardData.checklists) {
    for (const cl of cardData.checklists) {
      for (const item of (cl.items || [])) {
        checklistItems.push({
          text: item.text || item.title,
          completed: item.completed || item.checked || false,
          checklist_name: cl.title || cl.name
        })
      }
    }
  }

  emit('select-card', {
    type: 'board_link',
    linked_board_id: selectedBoardForCards.value?.id || null,
    linked_card_id: cardData.id,
    title: cardData.title,
    content: cardData.description || '',
    color: cardData.labels?.[0]?.color || selectedBoardForCards.value?.background_color || '#6366f1',
    card_data: {
      id: cardData.id,
      title: cardData.title,
      description: cardData.description,
      due_date: cardData.due_date,
      completed: cardData.completed,
      list_name: card.list_name, // Keep original list_name from flattened data
      board_name: selectedBoardForCards.value?.name,
      board_id: selectedBoardForCards.value?.id,
      labels: cardData.labels || [],
      assignees: cardData.assignees || [],
      checklists: cardData.checklists || [],
      checklist_items: checklistItems,
      attachment_count: cardData.attachment_count || 0
    }
  })
}

function isOverdue(card) {
  if (!card.due_date || card.completed) return false
  return new Date(card.due_date) < new Date()
}

function formatDate(date) {
  if (!date) return ''
  const d = new Date(date)
  const now = new Date()
  const diff = Math.ceil((d - now) / (1000 * 60 * 60 * 24))
  if (diff === 0) return 'Today'
  if (diff === 1) return 'Tomorrow'
  if (diff === -1) return 'Yesterday'
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

function getChecklistProgress(card) {
  if (!card.checklists?.length) return ''
  let total = 0, done = 0
  for (const cl of card.checklists) {
    for (const item of (cl.items || [])) {
      total++
      if (item.completed || item.checked) done++
    }
  }
  return `${done}/${total}`
}
</script>

